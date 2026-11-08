<?php

declare(strict_types=1);

namespace App\Http\Requests\Tracking;

use App\Models\Order\DeliveryOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Параметры просмотра трекинга заказа (ЧТЗ 8.5).
 *
 * limit — размер страницы ленты геоточек: по умолчанию 50, максимум 200.
 * Курьер и администратор к чтению трекинга не допускаются (403), чужой заказ
 * отсекается операцией как несуществующий (404).
 */
final class OrderTrackingRequest extends FormRequest
{
    /**
     * Сколько точек ленты отдаётся по умолчанию.
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Максимальный размер страницы ленты.
     */
    public const MAX_LIMIT = 200;

    /**
     * Чтение трекинга доступно ролям, которые видят заказы.
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
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    /**
     * Размер страницы из запроса или значение по умолчанию.
     */
    public function limit(): int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
    }

    /**
     * Курсор ленты из запроса; null — первая страница.
     */
    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? $cursor : null;
    }
}
