<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\Exceptions\CourierInactive;
use App\Domain\Dispatch\Exceptions\CourierShiftAlreadyOpen;
use App\Domain\Dispatch\Exceptions\CourierShiftOverlap;
use App\Domain\Dispatch\Shift;
use App\Domain\Dispatch\ShiftRules;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\CourierShift as CourierShiftModel;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Открытие смены курьером (FR-DSP-101…103, 105).
 *
 * Порядок: доменные правила по уже существующим сменам → запись смены →
 * исходящее событие в той же транзакции. Пересечение дополнительно отсекает
 * ограничение базы, поэтому гонка двух запросов даёт тот же код ошибки.
 */
final readonly class OpenShift
{
    /**
     * Тип агрегата исходящего события.
     */
    private const AGGREGATE_TYPE = 'courier_shift';

    /**
     * Тип события начала смены.
     */
    private const EVENT_STARTED = 'courier.shift.started';

    /**
     * @throws ApiException профиль неактивен, смена уже открыта или пересекается
     */
    public function handle(User $courier, ?int $zoneId): CourierShiftModel
    {
        return DB::transaction(function () use ($courier, $zoneId): CourierShiftModel {
            $courierId = (int) $courier->getKey();
            $startedAt = now()->toDateTimeImmutable();
            $candidate = Shift::open($courierId, $zoneId, $startedAt);

            try {
                ShiftRules::assertCanOpen(
                    $this->isActive($courier),
                    $this->existingShifts($courierId, $startedAt),
                    $candidate,
                );
            } catch (CourierInactive) {
                throw ApiException::courierInactive();
            } catch (CourierShiftAlreadyOpen) {
                throw ApiException::courierShiftAlreadyOpen();
            } catch (CourierShiftOverlap) {
                throw ApiException::courierShiftOverlap();
            }

            try {
                $shift = CourierShiftModel::query()->create([
                    'courier_id' => $courierId,
                    'zone_id' => $zoneId,
                    'started_at' => $startedAt,
                    'ended_at' => null,
                ]);
            } catch (QueryException $exception) {
                // Параллельное открытие: интервал отсекает ограничение базы.
                throw $this->conflict($exception);
            }

            OutboxMessage::query()->create([
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $shift->getKey(),
                'event_type' => self::EVENT_STARTED,
                // Персональных данных нет: только идентификаторы и время.
                'payload' => [
                    'shift_id' => $shift->getKey(),
                    'courier_id' => $courierId,
                    'zone_id' => $zoneId,
                    'started_at' => $startedAt->format(DateTimeInterface::ATOM),
                ],
            ]);

            return $shift;
        });
    }

    /**
     * Смену открывает только курьер с активным профилем.
     *
     * Отсутствие профиля — отказ в доступе (FR-DSP-105), а не конфликт состояния:
     * обычно его отсекает политика, здесь проверка страхует прямой вызов операции.
     */
    private function isActive(User $courier): bool
    {
        $profile = $courier->courierProfile()->first();

        if ($profile === null) {
            throw ApiException::forbidden();
        }

        return $profile->is_active;
    }

    /**
     * Смены курьера, которые могут пересечься с новой: открытые и закрытые позже начала.
     *
     * Выборка ограничена этим условием, поэтому правило не читает всю историю смен.
     *
     * @return list<Shift>
     */
    private function existingShifts(int $courierId, DateTimeImmutable $startedAt): array
    {
        return array_values(CourierShiftModel::query()
            ->where('courier_id', $courierId)
            ->where(static function (Builder $query) use ($startedAt): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>', $startedAt);
            })
            ->get()
            ->map(static fn (CourierShiftModel $shift): Shift => new Shift(
                (int) $shift->courier_id,
                $shift->zone_id === null ? null : (int) $shift->zone_id,
                DateTimeImmutable::createFromInterface($shift->started_at),
                $shift->ended_at === null ? null : DateTimeImmutable::createFromInterface($shift->ended_at),
            ))
            ->all());
    }

    /**
     * Нарушение ограничения базы переводится в тот же код, что и доменная проверка.
     */
    private function conflict(QueryException $exception): ApiException
    {
        /** @var array<int, mixed> $errorInfo */
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $state = (string) ($errorInfo[0] ?? '');

        return match ($state) {
            // exclusion_violation: смена пересеклась с созданной параллельно.
            '23P01' => ApiException::courierShiftOverlap(),
            // unique_violation: у курьера уже есть открытая смена.
            '23505' => ApiException::courierShiftAlreadyOpen(),
            default => throw $exception,
        };
    }
}
