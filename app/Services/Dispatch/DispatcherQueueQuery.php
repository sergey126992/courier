<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Domain\Order\OrderStatus;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Order\OrderListQuery;
use Illuminate\Pagination\CursorPaginator;

/**
 * Выборка очереди диспетчера (FR-UI-192, FR-UI-131).
 *
 * Очередь — это список заказов строго в статусе «создан» с дополнительными
 * фильтрами зоны, периода и транспорта. Каркас выборки, доступность по роли,
 * устойчивый порядок и курсор берутся у OrderListQuery: без фильтров зоны и
 * транспорта очередь совпадает с ним построчно и по тексту запроса.
 */
final readonly class DispatcherQueueQuery
{
    public function __construct(
        private OrderListQuery $orders,
    ) {}

    /**
     * Страница очереди: новые заказы сверху.
     *
     * Курсор можно передать явно: действие Livewire «Показать ещё» хранит
     * позицию выдачи в состоянии компонента, а не в адресе страницы.
     *
     * @param array<string, mixed> $filters zone_id, transport, from, to
     * @return CursorPaginator<int, DeliveryOrder>
     */
    public function page(User $user, array $filters = [], ?int $perPage = null, ?string $cursor = null): CursorPaginator
    {
        // Статус очереди не настраивается клиентом: всегда «создан».
        $query = $this->orders->query(
            $user,
            array_merge($filters, ['status' => [OrderStatus::Created->value]]),
        );

        $zone = $filters['zone_id'] ?? null;

        if (is_numeric($zone)) {
            $query->where('zone_id', (int) $zone);
        }

        $transport = $filters['transport'] ?? null;

        if (is_string($transport) && $transport !== '') {
            $query->where('transport_type', $transport);
        }

        return $this->orders->paginate($query, $perPage, true, $cursor);
    }

    /**
     * Справочник зон очереди: активные зоны для фильтра и подписи строк.
     *
     * Один запрос на страницу: подписи зон не перечитываются для каждой
     * строки, поэтому очередь укладывается в бюджет трёх SELECT (раздел 7 ЧТЗ).
     *
     * @return array{options: array<int, string>, names: array<int, string>}
     */
    public function zones(): array
    {
        $options = [];
        $names = [];

        $zones = ServiceZone::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        foreach ($zones as $zone) {
            $id = (int) $zone->getKey();
            $names[$id] = (string) $zone->name;

            // Фильтр предлагает только действующие зоны (FR-UI-132).
            if ((bool) $zone->is_active) {
                $options[$id] = (string) $zone->name;
            }
        }

        return ['options' => $options, 'names' => $names];
    }

    /**
     * Размер порции очереди: тот же, что у списка заказов
     * (config('ui.pagination.per_page'), предел — config('ui.pagination.max')).
     */
    public function perPage(?int $requested = null): int
    {
        return $this->orders->perPage($requested);
    }
}
