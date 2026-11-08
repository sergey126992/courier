<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Domain\Geography\Address;
use App\Domain\Geography\Coordinates;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\Parcel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Собирает входные данные расчёта из проверенных данных запроса.
 *
 * Одна и та же форма адресов и посылки используется расчётом и созданием
 * заказа, поэтому разбор полей живёт здесь, а не в каждом действии.
 * Расстояние приходит готовым: его определяет сервер, а не клиент.
 */
final readonly class EstimateInputFactory
{
    /**
     * @param array<string, mixed> $data проверенные данные запроса
     */
    public function build(array $data, Distance $distance): EstimateInput
    {
        return new EstimateInput(
            transport: TransportType::from((string) $data['transport']),
            lightVehicleKind: $this->lightVehicleKind($data),
            distance: $distance,
            parcel: $this->parcel($data),
            requestedAt: $this->requestedAt($data),
            isPaidParking: (bool) ($data['is_paid_parking'] ?? false),
        );
    }

    /**
     * Адрес из проверенных данных запроса: поле pickup или dropoff.
     *
     * @param array<string, mixed> $data проверенные данные запроса
     */
    public function address(array $data, string $field): Address
    {
        /** @var array<string, mixed> $address */
        $address = (array) ($data[$field] ?? []);

        return new Address(
            district: (string) ($address['district'] ?? ''),
            street: (string) ($address['street'] ?? ''),
            house: (string) ($address['house'] ?? ''),
            coordinates: new Coordinates(
                (float) ($address['latitude'] ?? 0),
                (float) ($address['longitude'] ?? 0),
            ),
        );
    }

    /**
     * Момент заявки: явное значение запроса или текущее время приложения.
     *
     * @param array<string, mixed> $data проверенные данные запроса
     */
    public function requestedAt(array $data): DateTimeImmutable
    {
        $timezone = (string) config('app.timezone');
        $requestedAt = $data['requested_at'] ?? null;

        return is_string($requestedAt) && $requestedAt !== ''
            ? CarbonImmutable::parse($requestedAt, $timezone)->toDateTimeImmutable()
            : CarbonImmutable::now($timezone)->toDateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $data проверенные данные запроса
     */
    private function lightVehicleKind(array $data): ?LightVehicleKind
    {
        $kind = $data['light_vehicle_kind'] ?? null;

        return is_string($kind) && $kind !== '' ? LightVehicleKind::from($kind) : null;
    }

    /**
     * @param array<string, mixed> $data проверенные данные запроса
     */
    private function parcel(array $data): Parcel
    {
        // Посылка всегда описана вложенным объектом parcel: одна форма во всём API.
        /** @var array<string, mixed> $parcel */
        $parcel = (array) ($data['parcel'] ?? []);
        /** @var array<string, mixed> $dimensions */
        $dimensions = (array) ($parcel['dimensions'] ?? []);

        return new Parcel(
            weightGrams: (int) ($parcel['weight_grams'] ?? 0),
            lengthCm: (int) ($dimensions['length'] ?? 0),
            widthCm: (int) ($dimensions['width'] ?? 0),
            heightCm: (int) ($dimensions['height'] ?? 0),
            declaredValue: $this->declaredValue($parcel),
        );
    }

    /**
     * Заявленная стоимость необязательна: без неё посылка считается без страховки.
     *
     * @param array<string, mixed> $parcel
     */
    private function declaredValue(array $parcel): ?Money
    {
        $value = $parcel['declared_value'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        return Money::fromMinor((int) $value['amount'], (string) $value['currency']);
    }
}
