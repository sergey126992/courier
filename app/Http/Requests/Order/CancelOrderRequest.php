<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Отмена заказа.
 *
 * Права проверяет политика DeliveryOrderPolicy в контроллере: чужой заказ
 * должен быть неотличим от несуществующего (404).
 */
final class CancelOrderRequest extends FormRequest
{
    /**
     * Правила причины отмены: один источник для API-запроса и Livewire-карточки.
     *
     * Длина совпадает с колонкой `order_status_history.reason` (varchar 255),
     * иначе причина прошла бы проверку, но не сохранилась.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::rulesFor();
    }
}
