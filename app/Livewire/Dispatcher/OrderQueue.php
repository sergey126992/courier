<?php

declare(strict_types=1);

namespace App\Livewire\Dispatcher;

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Money;
use App\Http\Requests\Dispatch\OrderQueueFilterRules;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Dispatch\DispatcherQueueQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Очередь заказов диспетчера (FR-UI-131…133).
 *
 * Читает только DispatcherQueueQuery: заказы строго в статусе «создан»,
 * новые сверху, курсорная выдача (FR-UI-131). Фильтры зоны, периода и
 * транспорта живут в адресе страницы через #[Url] и переживают
 * автообновление (FR-UI-132, 133).
 *
 * Автообновление — `wire:poll` по config('ui.dispatcher.refresh_seconds'):
 * обновляется только очередь, поэтому открытое модальное окно не «дёргается».
 * Пока включена пауза, атрибут poll не выводится вовсе — запросов нет.
 * Переходов и анимаций в шаблоне нет, поэтому при `prefers-reduced-motion:
 * reduce` обновление не сопровождается миганием (FR-UI-177).
 */
final class OrderQueue extends Component
{
    /**
     * Зона обслуживания из адреса страницы.
     *
     * Значение приходит строкой, поэтому нормализуется перед проверкой.
     */
    #[Url(as: 'zone')]
    public mixed $zoneId = null;

    /**
     * Начало периода создания заказа.
     */
    #[Url(as: 'from')]
    public ?string $from = null;

    /**
     * Конец периода создания заказа.
     */
    #[Url(as: 'to')]
    public ?string $to = null;

    /**
     * Тип транспорта заказа.
     */
    #[Url(as: 'transport')]
    public ?string $transport = null;

    /**
     * Пауза автообновления: в адрес не попадает, после перезагрузки очередь
     * снова обновляется (FR-UI-133).
     */
    public bool $paused = false;

    /**
     * Размер порции из config('ui.pagination.per_page').
     */
    public int $perPage = 0;

    /**
     * Интервал автообновления в секундах из config('ui.dispatcher.refresh_seconds').
     */
    public int $refreshSeconds = 0;

    /**
     * Накопленные строки всех загруженных порций: только скалярные значения,
     * чтобы состояние оставалось переносимым между запросами.
     *
     * @var list<array{id: int, number: string, zone: string, transport: string, weight: int, eta: int, price_amount: int, price_currency: string, created_at: string}>
     */
    public array $items = [];

    /**
     * Курсор следующей порции; null — выдача закончена.
     */
    public ?string $nextCursor = null;

    /**
     * Есть ли ещё порции: управляет кнопкой «Показать ещё».
     */
    public bool $hasMore = false;

    /**
     * Ошибка загрузки: показывается сообщением страницы (FR-UI-184).
     */
    public bool $failed = false;

    /**
     * Сколько порций уже загружено: столько же восстанавливает автообновление,
     * поэтому объём выдачи и позиция прокрутки не «прыгают» (FR-UI-133).
     */
    public int $pages = 0;

    /**
     * Справочник зон текущего запроса: id → название.
     *
     * @var array{options: array<int, string>, names: array<int, string>}|null
     */
    private ?array $zoneDictionary = null;

    /**
     * Первая порция с учётом фильтров из адреса страницы.
     */
    public function mount(DispatcherQueueQuery $query): void
    {
        $this->perPage = $query->perPage();
        $this->refreshSeconds = max(1, (int) config('ui.dispatcher.refresh_seconds'));

        $this->normalizeFilters();

        // Испорченные фильтры из адреса не роняют страницу: показываем всю очередь.
        if (! $this->filtersAreValid()) {
            $this->zoneId = null;
            $this->from = null;
            $this->to = null;
            $this->transport = null;
        }

        $this->reload($query);
    }

    /**
     * Новая порция: следующие строки добавляются к накопленной выдаче.
     */
    public function loadMore(DispatcherQueueQuery $query): void
    {
        if (! $this->hasMore || $this->nextCursor === null) {
            return;
        }

        $this->loadPage($query, $this->nextCursor);
    }

    /**
     * Применение фильтров: проверка и выдача с начала (FR-UI-132).
     */
    public function applyFilters(DispatcherQueueQuery $query): void
    {
        $this->normalizeFilters();

        Validator::make($this->filters(), OrderQueueFilterRules::rules())->validate();

        // Новый фильтр начинает выдачу заново: загруженные порции не сохраняются.
        $this->pages = 0;
        $this->reload($query);
    }

    /**
     * Пауза автообновления: состояние видно текстом, запросов нет (FR-UI-133).
     */
    public function togglePause(): void
    {
        $this->paused = ! $this->paused;
    }

    /**
     * Периодическое обновление: очередь перечитывается без перезагрузки страницы.
     *
     * Фильтры берутся из свойств, а число порций — из состояния, поэтому
     * прокрутка и выдача сохраняются. Во время паузы обновления нет даже
     * при прямом вызове действия.
     */
    public function refresh(DispatcherQueueQuery $query): void
    {
        if ($this->paused) {
            return;
        }

        $this->reload($query);
    }

    /**
     * Вычисляемая коллекция строк очереди для представления.
     *
     * Подписи, цена и время форматируются здесь, а не в Blade: шаблон не знает
     * о домене и не обращается к базе (FR-UI-142, 194).
     *
     * @return Collection<int, array<string, string|HtmlString>>
     */
    #[Computed]
    public function rows(): Collection
    {
        return collect($this->items)->map(fn (array $item): array => $this->displayRow($item));
    }

    /**
     * Действующие зоны для фильтра: значения справочника, а не литералы шаблона.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function zoneOptions(): array
    {
        return $this->zones()['options'];
    }

    /**
     * Типы транспорта для фильтра: значения перечисления и их подписи.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function transportOptions(): array
    {
        $options = [];

        foreach (TransportType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * Количество заказов в загруженной выдаче — для объявления `aria-live`.
     */
    #[Computed]
    public function orderCountLabel(): string
    {
        return (string) __('dispatch.labels.order_count', ['count' => count($this->items)]);
    }

    public function render(): View
    {
        return view('livewire.dispatcher.order-queue', [
            'columns' => [
                'number' => __('orders.labels.number'),
                'zone' => __('orders.labels.zone'),
                'transport' => __('orders.labels.transport'),
                'weight' => __('orders.labels.weight'),
                'eta' => __('orders.labels.eta'),
                'price' => __('orders.labels.price'),
                'created_at' => __('orders.labels.created_at'),
            ],
        ]);
    }

    /**
     * Выдача с начала: накопленные строки сбрасываются вместе с курсором,
     * затем восстанавливается прежнее число порций.
     */
    private function reload(DispatcherQueueQuery $query): void
    {
        $target = max(1, $this->pages);

        $this->items = [];
        $this->nextCursor = null;
        $this->hasMore = false;
        $this->failed = false;
        $this->pages = 0;

        $this->loadPage($query, null);

        while ($this->hasMore && $this->pages < $target) {
            $this->loadPage($query, $this->nextCursor);
        }
    }

    /**
     * Одна порция выборки через общий query-объект.
     */
    private function loadPage(DispatcherQueueQuery $query, ?string $cursor): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->failed = true;

            return;
        }

        try {
            $page = $query->page($user, $this->filters(), $this->perPage, $cursor);
        } catch (Throwable $exception) {
            // Ошибка загрузки не ломает страницу: сообщение и событие (FR-UI-184).
            report($exception);
            $this->failed = true;
            $this->dispatch('notify', type: 'error', message: (string) __('ui.states.load_error'));

            return;
        }

        foreach ($page->items() as $order) {
            $this->items[] = $this->toItem($order);
        }

        $next = $page->nextCursor();
        $this->nextCursor = $next !== null ? $next->encode() : null;
        $this->hasMore = $page->hasMorePages();
        $this->pages++;
    }

    /**
     * Фильтры в форме, которую понимает DispatcherQueueQuery и валидатор.
     *
     * @return array{zone_id: int|null, transport: string|null, from: string|null, to: string|null}
     */
    private function filters(): array
    {
        return [
            'zone_id' => $this->normalizedZoneId(),
            'transport' => $this->normalizedTransport(),
            'from' => $this->normalizedDate($this->from),
            'to' => $this->normalizedDate($this->to),
        ];
    }

    /**
     * Приведение значений из адреса страницы к скалярной форме.
     */
    private function normalizeFilters(): void
    {
        $this->zoneId = $this->normalizedZoneId();
        $this->transport = $this->normalizedTransport();
        $this->from = $this->normalizedDate($this->from);
        $this->to = $this->normalizedDate($this->to);
    }

    /**
     * Проверка фильтров без показа ошибок: нужна только при открытии страницы.
     */
    private function filtersAreValid(): bool
    {
        return ! Validator::make($this->filters(), OrderQueueFilterRules::rules())->fails();
    }

    /**
     * Идентификатор зоны: значение из адреса приходит строкой.
     */
    private function normalizedZoneId(): ?int
    {
        return is_numeric($this->zoneId) ? (int) $this->zoneId : null;
    }

    /**
     * Тип транспорта: пустая строка означает отсутствие фильтра.
     */
    private function normalizedTransport(): ?string
    {
        if (! is_string($this->transport)) {
            return null;
        }

        $value = trim($this->transport);

        return $value === '' ? null : $value;
    }

    /**
     * Дата фильтра: пустая строка означает отсутствие периода.
     */
    private function normalizedDate(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * Справочник зон: один запрос на запрос компонента.
     *
     * @return array{options: array<int, string>, names: array<int, string>}
     */
    private function zones(): array
    {
        if ($this->zoneDictionary === null) {
            $this->zoneDictionary = app(DispatcherQueueQuery::class)->zones();
        }

        return $this->zoneDictionary;
    }

    /**
     * Приводит модель к скалярной строке состояния Livewire.
     *
     * @return array{id: int, number: string, zone: string, transport: string, weight: int, eta: int, price_amount: int, price_currency: string, created_at: string}
     */
    private function toItem(DeliveryOrder $order): array
    {
        $parcel = (array) $order->parcel;
        $zoneId = (int) $order->zone_id;

        return [
            'id' => (int) $order->getKey(),
            'number' => (string) $order->number,
            // Подпись зоны — из справочника: строка не делает запрос на модель.
            'zone' => $this->zones()['names'][$zoneId] ?? '',
            'transport' => $this->transportLabel($order),
            'weight' => (int) ($parcel['weight_grams'] ?? 0),
            'eta' => (int) $order->eta_minutes,
            'price_amount' => (int) $order->price_amount,
            'price_currency' => (string) $order->price_currency,
            'created_at' => $order->created_at->format((string) __('ui.formats.datetime')),
        ];
    }

    /**
     * Строка очереди: те же поля, что видит диспетчер в API-выдаче.
     *
     * @param array{id: int, number: string, zone: string, transport: string, weight: int, eta: int, price_amount: int, price_currency: string, created_at: string} $item
     * @return array<string, string|HtmlString>
     */
    private function displayRow(array $item): array
    {
        // Карточка диспетчера появится отдельным экраном: ссылка только если
        // маршрут объявлен, иначе номер остаётся текстом.
        $url = Route::has('dispatcher.orders.show')
            ? route('dispatcher.orders.show', ['order' => $item['id']])
            : null;

        return [
            'number' => $url !== null
                ? new HtmlString(Blade::render(
                    '<x-app.link :href="$url" class="inline-flex min-h-11 items-center sm:min-h-0">{{ $number }}</x-app.link>',
                    ['url' => $url, 'number' => $item['number']],
                ))
                : $item['number'],
            'zone' => $item['zone'],
            'transport' => $item['transport'],
            'weight' => (string) __('ui.formats.grams', ['grams' => $item['weight']]),
            'eta' => (string) __('ui.formats.minutes', ['minutes' => $item['eta']]),
            'price' => Money::fromMinor($item['price_amount'], $item['price_currency'])->format(),
            'created_at' => $item['created_at'],
        ];
    }

    /**
     * Транспорт заказа: вид лёгкого транспорта уточняет тип.
     */
    private function transportLabel(DeliveryOrder $order): string
    {
        $kind = $order->light_vehicle_kind;

        return $kind !== null
            ? $order->transport_type->label().' · '.$kind->label()
            : $order->transport_type->label();
    }
}
