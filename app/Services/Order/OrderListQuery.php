<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * Единый источник выборки списка заказов (FR-UI-191).
 *
 * Фильтры статуса и периода, доступность по роли и устойчивый порядок
 * (created_at, id) собраны здесь; наружу отдаётся страница курсора, а не
 * ресурсы: форму ответа и его поля по-прежнему задаёт контроллер.
 */
final class OrderListQuery
{
    /**
     * Имя параметра курсора в адресе — то же, что у API.
     */
    private const CURSOR_NAME = 'cursor';

    /**
     * Запрос с фильтрами и доступностью по роли.
     *
     * @param array<string, mixed> $filters проверенные параметры списка
     * @return Builder<DeliveryOrder>
     */
    public function query(User $user, array $filters = []): Builder
    {
        // Диспетчер видит заказы всех отправителей, отправитель — только свои.
        $query = DeliveryOrder::query()->visibleTo($user);

        $this->applyListFilters($query, $filters);

        return $query;
    }

    /**
     * Страница курсора с устойчивым порядком по времени создания и id.
     *
     * Курсор можно передать явно: Livewire-действие «Показать ещё» не несёт
     * параметра в адресе запроса и берёт позицию из своего состояния.
     *
     * @param Builder<DeliveryOrder> $query
     * @return CursorPaginator<int, DeliveryOrder>
     */
    public function paginate(
        Builder $query,
        ?int $perPage = null,
        bool $newestFirst = true,
        ?string $cursor = null,
    ): CursorPaginator {
        if ($newestFirst) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            $query->orderBy('created_at')->orderBy('id');
        }

        // Явно переданный курсор приоритетнее параметра адреса: Livewire-действие
        // хранит позицию выдачи в своём состоянии (FR-UI-125).
        $position = $cursor !== null && $cursor !== '' ? Cursor::fromEncoded($cursor) : null;

        return $query->cursorPaginate(
            perPage: $this->perPage($perPage),
            columns: ['*'],
            cursorName: self::CURSOR_NAME,
            cursor: $position,
        );
    }

    /**
     * Размер страницы: запрошенный, но не больше предела конфигурации.
     *
     * Значение по умолчанию берётся из config('ui.pagination.per_page'),
     * предел — из config('ui.pagination.max') (раздел 7 ЧТЗ).
     */
    public function perPage(?int $requested = null): int
    {
        $default = (int) config('ui.pagination.per_page');
        $max = (int) config('ui.pagination.max');
        $perPage = $requested ?? $default;

        return max(1, min($perPage, $max));
    }

    /**
     * Фильтры списка: статусы и период создания.
     *
     * @param Builder<DeliveryOrder> $query
     * @param array<string, mixed> $filters
     */
    private function applyListFilters(Builder $query, array $filters): void
    {
        $statuses = $filters['status'] ?? null;

        if (is_array($statuses) && $statuses !== []) {
            $query->whereIn('status', array_map(static fn (mixed $status): string => (string) $status, $statuses));
        }

        $from = $filters['from'] ?? null;

        if (is_string($from) && $from !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($from));
        }

        $to = $filters['to'] ?? null;

        if (is_string($to) && $to !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse($to));
        }
    }
}
