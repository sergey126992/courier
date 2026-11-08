<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Входные данные предварительного расчёта доставки.
 *
 * Здесь проверяется только состав и формат полей. Пределы транспорта, радиус
 * обслуживания и зоны — правила домена и сервера: их нарушение возвращается
 * отдельными кодами. Расстояние клиент не передаёт: его считает сервер по
 * координатам адресов забора и доставки (FR-GEO-112, FR-GEO-113).
 */
final class EstimateDeliveryRequest extends FormRequest
{
    /**
     * Расчёт доступен роли, которой разрешено создавать заказы (отправитель).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('estimate-delivery');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return EstimatePayloadRules::rules($this->all());
    }
}
