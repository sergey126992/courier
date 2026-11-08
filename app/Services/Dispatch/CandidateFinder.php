<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\Contracts\CourierAssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Geography\Exceptions\InvalidCoordinates;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\Role;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\TransportParams;
use App\Models\Order\DeliveryOrder;
use App\Services\Pricing\PricingConfig;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Отбор подходящих курьеров для заказа (FR-DSP-111…118, 122…124).
 *
 * Жёсткие фильтры выполняет один запрос с соединениями: роль, активный профиль,
 * открытая смена, отсутствие активного назначения, тип транспорта, зона смены,
 * вес и радиус посылки. Метрики подбора (расстояние до забора, время подхода,
 * загрузка) считаются здесь же, а порядок задаёт правило снаружи — так новое
 * правило не меняет отбор (FR-DSP-126).
 *
 * Список кандидатов не должен падать из-за внешнего провайдера расстояний:
 * недоступность провайдера или некорректная позиция оставляют кандидата без
 * расстояния, и он получает худший ранг, но не исключается (FR-DSP-116).
 */
final readonly class CandidateFinder
{
    public function __construct(
        private PricingConfig $pricing,
        private DistanceProvider $distances,
    ) {}

    /**
     * Кандидаты в порядке применённого правила, не больше лимита.
     *
     * @return list<CourierCandidate>
     */
    public function find(DeliveryOrder $order, CourierAssignmentStrategy $strategy, int $limit): array
    {
        return array_slice($strategy->rank($this->collect($order)), 0, max(0, $limit));
    }

    /**
     * Подходящие курьеры с метриками подбора, в порядке идентификатора.
     *
     * @return list<CourierCandidate>
     */
    private function collect(DeliveryOrder $order): array
    {
        $params = $this->pricing->transportParams($order->transport_type);

        // Тип транспорта курьера обязан совпадать с заказом (FR-DSP-113), поэтому
        // вес и радиус проверяются один раз по параметрам этого же типа (FR-DSP-115).
        if (! $this->parcelFits($order, $params)) {
            return [];
        }

        $pickup = $this->coordinates((array) $order->pickup_address);
        $isPeakHour = $params->isPeakHour(now()->toDateTimeImmutable());

        $candidates = [];

        foreach ($this->query($order)->get() as $row) {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            $kind = $this->lightVehicleKind($data['light_vehicle_kind'] ?? null);
            [$distanceKm, $approachMinutes] = $this->approach($data, $pickup, $params, $kind, $isPeakHour);

            $candidates[] = new CourierCandidate(
                courierId: (int) $data['courier_id'],
                name: (string) $data['name'],
                transport: TransportType::from((string) $data['transport_type']),
                lightVehicleKind: $kind,
                distanceToPickupKm: $distanceKm,
                assignmentsInShift: (int) ($data['assignments_in_shift'] ?? 0),
                pickupApproachMinutes: $approachMinutes,
                orderEtaMinutes: $order->eta_minutes,
            );
        }

        return $candidates;
    }

    /**
     * Запрос кандидатов: один запрос с соединениями и подзапросом загрузки.
     *
     * Соединение со сменами идёт только по открытым сменам, поэтому в выборке
     * не появляются закрытые (FR-DSP-112). Смена без зоны подходит для заказа
     * любой зоны (FR-DSP-114). Загрузка — число назначений с начала смены
     * (FR-DSP-123), активные назначения исключены отдельным условием.
     */
    private function query(DeliveryOrder $order): Builder
    {
        $assignmentsInShift = DB::table('assignments')
            ->selectRaw('count(*)')
            ->whereColumn('assignments.courier_id', 'users.id')
            ->whereColumn('assignments.assigned_at', '>=', 'courier_shifts.started_at');

        return DB::table('users')
            ->join('courier_profiles as profiles', 'profiles.user_id', '=', 'users.id')
            ->join('courier_shifts as courier_shifts', static function (JoinClause $join): void {
                $join->on('courier_shifts.courier_id', '=', 'users.id')
                    ->whereNull('courier_shifts.ended_at');
            })
            ->leftJoin('courier_last_positions as positions', 'positions.courier_id', '=', 'users.id')
            ->where('users.role', Role::Courier->value)
            ->where('profiles.is_active', true)
            ->where('profiles.transport_type', $order->transport_type->value)
            ->where(static function (Builder $query) use ($order): void {
                $query->whereNull('courier_shifts.zone_id')
                    ->orWhere('courier_shifts.zone_id', $order->zone_id);
            })
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('assignments')
                    ->whereColumn('assignments.courier_id', 'users.id')
                    ->whereIn('assignments.status', AssignmentStatus::activeValues());
            })
            ->select([
                'users.id as courier_id',
                'users.name as name',
                'profiles.transport_type as transport_type',
                'profiles.light_vehicle_kind as light_vehicle_kind',
                'positions.latitude as latitude',
                'positions.longitude as longitude',
            ])
            ->selectSub($assignmentsInShift, 'assignments_in_shift')
            ->orderBy('users.id');
    }

    /**
     * Проходит ли посылка по весу и радиусу транспорта (FR-DSP-115).
     *
     * Превышение предела исключает всех кандидатов этого типа транспорта.
     */
    private function parcelFits(DeliveryOrder $order, TransportParams $params): bool
    {
        /** @var array<string, mixed> $parcel */
        $parcel = (array) $order->parcel;

        return (int) ($parcel['weight_grams'] ?? 0) <= $params->maxWeightGrams()
            && $order->distance_km <= $params->maxRadiusKm();
    }

    /**
     * Расстояние до забора и время подхода к нему.
     *
     * Время подхода — расстояние, делённое на скорость транспорта с учётом часа
     * пик; полные минуты округляются вверх, потому что подъезд не может быть
     * быстрее расчётного. Без позиции оба значения пустые.
     *
     * @param array<string, mixed> $row строка выборки кандидатов
     * @return array{0: float|null, 1: int|null}
     */
    private function approach(
        array $row,
        ?Coordinates $pickup,
        TransportParams $params,
        ?LightVehicleKind $kind,
        bool $isPeakHour,
    ): array {
        $latitude = $row['latitude'] ?? null;
        $longitude = $row['longitude'] ?? null;

        if ($pickup === null || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [null, null];
        }

        try {
            $distance = $this->distances->between(
                new Coordinates((float) $latitude, (float) $longitude),
                $pickup,
            );
        } catch (DistanceProviderUnavailable|InvalidCoordinates) {
            // Деградация без исключения из списка: кандидат остаётся, но без метрики.
            return [null, null];
        }

        $kilometers = $distance->kilometers();
        $speedKmH = $params->speedKmH($kind, $isPeakHour);

        return [
            $kilometers,
            $speedKmH > 0 ? (int) ceil($kilometers / $speedKmH * 60) : null,
        ];
    }

    /**
     * Координаты точки забора из адреса заказа.
     *
     * @param array<string, mixed> $address
     */
    private function coordinates(array $address): ?Coordinates
    {
        $latitude = $address['latitude'] ?? null;
        $longitude = $address['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        try {
            return new Coordinates((float) $latitude, (float) $longitude);
        } catch (InvalidCoordinates) {
            return null;
        }
    }

    /**
     * Вид лёгкого транспорта из строки выборки; у остальных типов он пуст.
     */
    private function lightVehicleKind(mixed $value): ?LightVehicleKind
    {
        return is_string($value) && $value !== '' ? LightVehicleKind::tryFrom($value) : null;
    }
}
