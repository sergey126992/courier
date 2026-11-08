<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Models\Order\DeliveryOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Параметры списка заказов.
 *
 * Фильтры и сортировка допускаются только из разрешённого перечня (FR-ORD-133):
 * всё остальное отклоняется валидацией с кодом 422. Набор правил общий с
 * веб-списком и живёт в OrderFilterRules (FR-UI-195).
 */
final class ListOrdersRequest extends FormRequest
{
    /**
     * Список видят отправитель (свои заказы) и диспетчер (все).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', DeliveryOrder::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return OrderFilterRules::rules();
    }

    /**
     * Новые заказы сверху — поведение по умолчанию.
     */
    public function newestFirst(): bool
    {
        return $this->input('sort', '-created_at') === '-created_at';
    }

    /**
     * Размер страницы из запроса или значение по умолчанию из конфигурации.
     *
     * Предел и значение по умолчанию заданы в config('ui.pagination'),
     * итоговый размер ограничивает OrderListQuery (раздел 7 ЧТЗ).
     */
    public function limit(): int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : (int) config('ui.pagination.per_page');
    }
}
