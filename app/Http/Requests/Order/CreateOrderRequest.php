<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Models\Order\DeliveryOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Создание заказа на доставку (ЧТЗ 8.2).
 *
 * Адреса описаны той же формой, что в расчёте; зону и расстояние определяет
 * сервер, поэтому поле зоны отклоняется правилом prohibited. Момент заявки
 * не может быть в прошлом сверх допуска из конфигурации.
 */
final class CreateOrderRequest extends FormRequest
{
    /**
     * Заказ создаёт подтверждённый отправитель (FR-ORD-101, раздел 6 ЧТЗ).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', DeliveryOrder::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return OrderPayloadRules::rules($this->all());
    }
}
