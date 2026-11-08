<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Domain\Order\OrderStatus;
use Illuminate\Validation\Rule;

/**
 * Правила фильтров списка заказов (FR-UI-195, FR-ORD-133).
 *
 * Один набор для API и веб-списка: фильтры и сортировка допускаются только
 * из разрешённого перечня, всё остальное отклоняется валидацией с кодом 422.
 */
final class OrderFilterRules
{
    /**
     * Максимальный размер страницы списка.
     */
    public const MAX_LIMIT = 100;

    /**
     * Разрешённые направления сортировки по времени создания.
     *
     * @var list<string>
     */
    public const SORTS = ['created_at', '-created_at'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'status' => ['sometimes', 'array', 'min:1'],
            'status.*' => ['string', Rule::enum(OrderStatus::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
        ];
    }
}
