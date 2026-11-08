<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Domain\Geography\Address;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Exceptions\AddressOutsideServiceArea;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Geography\Zone;
use App\Domain\Geography\ZoneResolver;
use App\Domain\Order\OrderNumber;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\TrackingCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;
use App\Domain\Pricing\Exceptions\UnsupportedTransport;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\StrategyDeliveryEstimator;
use App\Http\Problem\ApiException;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder as DeliveryOrderModel;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Events\OrderEventPayload;
use App\Services\Pricing\EstimateInputFactory;
use Illuminate\Support\Facades\DB;

/**
 * Создание заказа на доставку.
 *
 * Порядок: адреса → зона по забору → расстояние провайдером → расчёт M2 →
 * транзакция записи. Клиент не передаёт ни зону, ни расстояние: и то и другое
 * определяет сервер. Заказ, первая запись истории и исходящее событие пишутся
 * в одной транзакции (FR-ORD-107).
 */
final readonly class CreateOrder
{
    /**
     * Префикс типа агрегата в исходящих событиях.
     */
    private const AGGREGATE_TYPE = 'order';

    /**
     * Тип события создания заказа.
     */
    private const EVENT_CREATED = 'order.created';

    public function __construct(
        private EstimateInputFactory $inputs,
        private ZoneResolver $zoneResolver,
        private DistanceProvider $distanceProvider,
        private StrategyDeliveryEstimator $estimator,
    ) {}

    /**
     * @param array<string, mixed> $data проверенные данные запроса
     *
     * @throws ApiException адрес вне зон, провайдер недоступен или транспорт не подходит
     */
    public function handle(User $sender, array $data): DeliveryOrderModel
    {
        $pickup = $this->inputs->address($data, 'pickup');
        $dropoff = $this->inputs->address($data, 'dropoff');

        try {
            $zone = $this->zoneResolver->resolve($pickup->coordinates());
        } catch (AddressOutsideServiceArea) {
            throw ApiException::addressOutsideServiceArea('pickup');
        }

        $input = $this->inputs->build($data, $this->distance($pickup, $dropoff));
        $quote = $this->quote($input);

        return DB::transaction(fn (): DeliveryOrderModel => $this->persist($sender, $data, $pickup, $dropoff, $zone, $quote, $input));
    }

    /**
     * @throws ApiException провайдер расстояний недоступен
     */
    private function distance(Address $pickup, Address $dropoff): Distance
    {
        try {
            return $this->distanceProvider->between($pickup->coordinates(), $dropoff->coordinates());
        } catch (DistanceProviderUnavailable) {
            throw ApiException::distanceProviderUnavailable();
        }
    }

    /**
     * @throws ApiException транспорт не подходит по весу, радиусу или не поддержан
     */
    private function quote(EstimateInput $input): PriceQuote
    {
        try {
            return $this->estimator->estimate($input);
        } catch (ParcelTooHeavyForTransport $exception) {
            throw ApiException::parcelTooHeavyForTransport($exception->maxWeightGrams(), $exception->transport());
        } catch (ParcelOutsideDeliveryRadius $exception) {
            throw ApiException::parcelOutsideDeliveryRadius($exception->maxRadiusKm(), $exception->transport());
        } catch (UnsupportedTransport $exception) {
            throw ApiException::unsupportedTransport($exception->transport());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persist(
        User $sender,
        array $data,
        Address $pickup,
        Address $dropoff,
        Zone $zone,
        PriceQuote $quote,
        EstimateInput $input,
    ): DeliveryOrderModel {
        $order = DeliveryOrderModel::query()->create([
            'number' => $this->nextNumber()->value(),
            'tracking_code' => TrackingCode::generate()->value(),
            'sender_id' => $sender->getKey(),
            'zone_id' => $this->zoneId($zone),
            'transport_type' => $quote->transport()->value,
            'light_vehicle_kind' => $quote->lightVehicleKind()?->value,
            'pickup_address' => $this->addressData($pickup),
            'dropoff_address' => $this->addressData($dropoff),
            'parcel' => $this->parcelData($data),
            'distance_km' => $quote->distance()->kilometers(),
            'price_amount' => $quote->total()->amount(),
            'price_currency' => $quote->total()->currency(),
            'price_breakdown' => $this->breakdownData($quote),
            'eta_minutes' => $quote->etaMinutes(),
            'requested_at' => $input->requestedAt(),
        ]);

        // Статус «создан» ставит DEFAULT базы: перечитываем модель, чтобы
        // в ответ и в историю попали значения из строки, а не пустые поля.
        $order->refresh();

        // Первая запись истории: прежнего статуса нет, автор — создатель заказа.
        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => null,
            'to_status' => OrderStatus::Created,
            'changed_by' => $sender->getKey(),
            'reason' => null,
        ]);

        // Персональных данных нет: идентификаторы, код отслеживания, статус,
        // район, транспорт и сумма — сервису оповещений этого хватает (FR-NTF-223).
        $event = OrderEventPayload::build(
            order: $order,
            status: OrderStatus::Created->value,
            occurredAt: now(),
            fields: [
                'pickup_district' => $pickup->district(),
                'transport' => $quote->transport()->value,
                'price_amount' => $quote->total()->amount(),
                'price_currency' => $quote->total()->currency(),
            ],
        );

        OutboxMessage::query()->create([
            'message_id' => $event['message_id'],
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $order->getKey(),
            'event_type' => self::EVENT_CREATED,
            'payload' => $event['payload'],
        ]);

        return $order;
    }

    /**
     * Номер выдаёт последовательность базы: приложение его не вычисляет (FR-ORD-105).
     */
    private function nextNumber(): OrderNumber
    {
        /** @var array<string, mixed> $row */
        $row = (array) DB::selectOne("select nextval('order_number_seq') as value");

        return OrderNumber::fromSequence((int) $row['value'], (int) now()->format('Y'));
    }

    /**
     * Идентификатор зоны для ссылки заказа: зона уже найдена доменом по коду.
     */
    private function zoneId(Zone $zone): int
    {
        return (int) ServiceZone::query()
            ->whereRaw('lower(code) = ?', [mb_strtolower($zone->code())])
            ->valueOrFail('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function addressData(Address $address): array
    {
        return [
            'district' => $address->district(),
            'street' => $address->street(),
            'house' => $address->house(),
            'latitude' => $address->coordinates()->latitude(),
            'longitude' => $address->coordinates()->longitude(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function parcelData(array $data): array
    {
        /** @var array<string, mixed> $parcel */
        $parcel = (array) ($data['parcel'] ?? []);
        /** @var array<string, mixed> $dimensions */
        $dimensions = (array) ($parcel['dimensions'] ?? []);
        $declared = $parcel['declared_value'] ?? null;

        return [
            'weight_grams' => (int) ($parcel['weight_grams'] ?? 0),
            'length_cm' => (int) ($dimensions['length'] ?? 0),
            'width_cm' => (int) ($dimensions['width'] ?? 0),
            'height_cm' => (int) ($dimensions['height'] ?? 0),
            'declared_value' => is_array($declared) ? [
                'amount' => (int) $declared['amount'],
                'currency' => (string) $declared['currency'],
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function breakdownData(PriceQuote $quote): array
    {
        return array_map(
            static fn (BreakdownLine $line): array => [
                'code' => $line->code()->value,
                'label' => $line->label(),
                'amount' => $line->amount()->amount(),
            ],
            $quote->lines(),
        );
    }
}
