<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use Illuminate\Validation\Rule;

/**
 * Правила расчёта доставки по адресам (FR-UI-195).
 *
 * Здесь проверяется только состав и формат полей: пределы транспорта, радиус
 * обслуживания и зоны — правила домена и сервера. Готовое расстояние клиент
 * не передаёт: его считает сервер по координатам (FR-GEO-112, FR-GEO-113).
 *
 * Набор общий для API-расчёта и веб-форм: расхождение правил запрещено.
 */
final class EstimatePayloadRules
{
    /**
     * @param array<string, mixed> $data данные формы для условных правил
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $data): array
    {
        $light = TransportType::Light->value;
        $hasParcel = static fn (): bool => filled(data_get($data, 'parcel'));
        $hasDimensions = static fn (): bool => filled(data_get($data, 'parcel.dimensions'));

        return [
            'transport' => ['required', Rule::enum(TransportType::class)],
            // Вид лёгкого транспорта обязателен только для типа «лёгкий».
            'light_vehicle_kind' => [
                'nullable',
                'required_if:transport,'.$light,
                'prohibited_unless:transport,'.$light,
                Rule::enum(LightVehicleKind::class),
            ],
            // Готовое расстояние не принимается: правило `prohibited` даёт явный
            // отказ, а не молчаливое игнорирование подставленного клиентом числа.
            'distance_km' => ['prohibited'],
            'pickup' => ['required', 'array:district,street,house,latitude,longitude'],
            'pickup.district' => ['required', 'string', 'max:255'],
            'pickup.street' => ['required', 'string', 'max:255'],
            'pickup.house' => ['required', 'string', 'max:255'],
            'pickup.latitude' => ['required', 'numeric', 'between:-90,90', 'decimal:0,6'],
            'pickup.longitude' => ['required', 'numeric', 'between:-180,180', 'decimal:0,6'],
            'dropoff' => ['required', 'array:district,street,house,latitude,longitude'],
            'dropoff.district' => ['required', 'string', 'max:255'],
            'dropoff.street' => ['required', 'string', 'max:255'],
            'dropoff.house' => ['required', 'string', 'max:255'],
            'dropoff.latitude' => ['required', 'numeric', 'between:-90,90', 'decimal:0,6'],
            'dropoff.longitude' => ['required', 'numeric', 'between:-180,180', 'decimal:0,6'],
            // Посылка описана вложенным объектом — та же форма, что при создании заказа.
            'parcel' => ['required', 'array:weight_grams,dimensions,declared_value'],
            'parcel.weight_grams' => [Rule::requiredIf($hasParcel), 'integer', 'gt:0'],
            'parcel.dimensions' => [Rule::requiredIf($hasParcel), 'array:length,width,height'],
            'parcel.dimensions.length' => [Rule::requiredIf($hasDimensions), 'integer', 'gt:0'],
            'parcel.dimensions.width' => [Rule::requiredIf($hasDimensions), 'integer', 'gt:0'],
            'parcel.dimensions.height' => [Rule::requiredIf($hasDimensions), 'integer', 'gt:0'],
            'parcel.declared_value' => ['sometimes', 'array:amount,currency'],
            'parcel.declared_value.amount' => ['required_with:parcel.declared_value', 'integer', 'min:0'],
            'parcel.declared_value.currency' => ['required_with:parcel.declared_value', 'string', 'size:3'],
            'requested_at' => ['sometimes', 'date'],
            'is_paid_parking' => ['sometimes', 'boolean'],
        ];
    }
}
