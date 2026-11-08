<?php

declare(strict_types=1);

namespace App\Livewire\Cabinet;

use App\Domain\Order\OrderStatus;
use App\Domain\Pricing\Money;
use App\Http\Requests\Order\OrderFilterRules;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Order\OrderListQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Список заказов отправителя: фильтры статуса и периода, курсорная выдача.
 *
 * Данные читает только OrderListQuery (FR-UI-191, 194), фильтры проверяются
 * правилами OrderFilterRules (FR-UI-195), а значения фильтров живут в адресе
 * страницы через #[Url] и восстанавливаются при возврате (FR-UI-125).
 */
final class OrderList extends Component
{
    /**
     * Выбранные статусы из адреса страницы.
     *
     * Значение из строки запроса может прийти не массивом, поэтому набор
     * нормализуется перед проверкой и запросом.
     */
    #[Url(as: 'status')]
    public mixed $statuses = [];

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
     * Размер порции из config('ui.pagination.per_page').
     */
    public int $perPage = 0;

    /**
     * Накопленные строки всех загруженных порций: только скалярные значения,
     * чтобы состояние компонента оставалось переносимым между запросами.
     *
     * @var list<array{id: int, number: string, status: string, transport: string, eta: int, price_amount: int, price_currency: string, created_at: string}>
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
     * Первая порция с учётом фильтров из адреса страницы.
     */
    public function mount(OrderListQuery $query): void
    {
        $this->perPage = $query->perPage();
        $this->statuses = $this->normalizedStatuses();
        $this->from = $this->normalizedDate($this->from);
        $this->to = $this->normalizedDate($this->to);

        // Испорченные фильтры не роняют страницу: выдача начинается без них.
        if (! $this->filtersAreValid()) {
            $this->statuses = [];
            $this->from = null;
            $this->to = null;
        }

        // Курсор в адресе — запасной путь без JavaScript: страница открывается
        // с позиции, указанной в ссылке «Показать ещё».
        $this->reload($query, $this->cursorFromRequest());
    }

    /**
     * Новая порция: следующие строки добавляются к накопленной выдаче.
     */
    public function loadMore(OrderListQuery $query): void
    {
        if (! $this->hasMore || $this->nextCursor === null) {
            return;
        }

        $this->loadPage($query, $this->nextCursor);
    }

    /**
     * Применение фильтров: проверка и выдача с начала (FR-UI-125).
     */
    public function applyFilters(OrderListQuery $query): void
    {
        $this->statuses = $this->normalizedStatuses();
        $this->from = $this->normalizedDate($this->from);
        $this->to = $this->normalizedDate($this->to);

        Validator::make($this->filterValues(), $this->filterRules())->validate();

        $this->reload($query);
    }

    /**
     * Сброс фильтров и возврат к полной выдаче.
     */
    public function resetFilters(OrderListQuery $query): void
    {
        $this->reset('statuses', 'from', 'to');
        $this->resetValidation();
        $this->statuses = [];

        $this->reload($query);
    }

    /**
     * Вычисляемая коллекция строк списка для представления.
     *
     * Бейдж статуса и цена форматируются здесь, а не в Blade: шаблон не знает
     * о домене и не обращается к базе (FR-UI-142, 194).
     *
     * @return Collection<int, array<string, string|HtmlString>>
     */
    #[Computed]
    public function orders(): Collection
    {
        return collect($this->items)->map(fn (array $item): array => $this->displayRow($item));
    }

    /**
     * Есть ли активный фильтр: от этого зависит причина пустого состояния.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->normalizedStatuses() !== []
            || $this->normalizedDate($this->from) !== null
            || $this->normalizedDate($this->to) !== null;
    }

    /**
     * Адрес следующей порции для навигации без JavaScript.
     */
    #[Computed]
    public function nextPageUrl(): ?string
    {
        if (! $this->hasMore || $this->nextCursor === null) {
            return null;
        }

        $parameters = array_filter([
            'status' => $this->normalizedStatuses(),
            'from' => $this->normalizedDate($this->from),
            'to' => $this->normalizedDate($this->to),
            'cursor' => $this->nextCursor,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        return route('cabinet.orders', $parameters);
    }

    public function render(): View
    {
        return view('livewire.cabinet.order-list', [
            'columns' => [
                'number' => __('orders.labels.number'),
                'status' => __('orders.labels.status'),
                'transport' => __('orders.labels.transport'),
                'eta' => __('orders.labels.eta'),
                'price' => __('orders.labels.price'),
                'created_at' => __('orders.labels.created_at'),
            ],
        ]);
    }

    /**
     * Выдача с начала: накопленные строки сбрасываются вместе с курсором.
     */
    private function reload(OrderListQuery $query, ?string $startCursor = null): void
    {
        $this->items = [];
        $this->nextCursor = null;
        $this->hasMore = false;
        $this->failed = false;

        $this->loadPage($query, $startCursor);
    }

    /**
     * Позиция выдачи из адреса страницы: нужна только при открытии ссылки
     * «Показать ещё» в браузере без JavaScript.
     */
    private function cursorFromRequest(): ?string
    {
        $cursor = request()->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * Одна порция выборки через общий query-объект.
     */
    private function loadPage(OrderListQuery $query, ?string $cursor): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->failed = true;

            return;
        }

        try {
            $page = $query->paginate(
                $query->query($user, $this->filters()),
                $this->perPage,
                true,
                $cursor,
            );
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
    }

    /**
     * Фильтры в форме, которую понимает OrderListQuery.
     *
     * @return array{status: list<string>, from: string|null, to: string|null}
     */
    private function filters(): array
    {
        return [
            'status' => $this->normalizedStatuses(),
            'from' => $this->normalizedDate($this->from),
            'to' => $this->normalizedDate($this->to),
        ];
    }

    /**
     * Те же значения в форме свойств компонента — для валидатора.
     *
     * @return array{statuses: list<string>, from: string|null, to: string|null}
     */
    private function filterValues(): array
    {
        return [
            'statuses' => $this->normalizedStatuses(),
            'from' => $this->normalizedDate($this->from),
            'to' => $this->normalizedDate($this->to),
        ];
    }

    /**
     * Правила фильтров из общего класса, привязанные к свойствам компонента.
     *
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(): array
    {
        $rules = OrderFilterRules::rules();

        return [
            // Пустой набор статусов означает «без фильтра», поэтому нижняя
            // граница размера из API-правила в форме не применяется.
            'statuses' => array_values(array_filter(
                $rules['status'],
                static fn (mixed $rule): bool => $rule !== 'min:1',
            )),
            'statuses.*' => $rules['status.*'],
            'from' => $this->nullableRule($rules['from']),
            'to' => $this->nullableRule($rules['to']),
        ];
    }

    /**
     * Пустой период — отсутствие фильтра, а не ошибка формата.
     *
     * @param array<int, mixed> $rules
     * @return array<int, mixed>
     */
    private function nullableRule(array $rules): array
    {
        return array_values(array_map(
            static fn (mixed $rule): mixed => $rule === 'sometimes' ? 'nullable' : $rule,
            $rules,
        ));
    }

    /**
     * Проверка фильтров без показа ошибок: нужна только при открытии страницы.
     */
    private function filtersAreValid(): bool
    {
        return ! Validator::make($this->filterValues(), $this->filterRules())->fails();
    }

    /**
     * Набор статусов списком строк: скалярное значение из адреса оборачивается.
     *
     * @return list<string>
     */
    private function normalizedStatuses(): array
    {
        $value = $this->statuses;

        if (is_string($value)) {
            $value = trim($value) === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $statuses = [];

        foreach ($value as $status) {
            if (is_scalar($status) && (string) $status !== '') {
                $statuses[] = (string) $status;
            }
        }

        return array_values(array_unique($statuses));
    }

    /**
     * Дата фильтра: пустая строка означает отсутствие периода.
     */
    private function normalizedDate(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * Приводит модель к скалярной строке состояния Livewire.
     *
     * @return array{id: int, number: string, status: string, transport: string, eta: int, price_amount: int, price_currency: string, created_at: string}
     */
    private function toItem(DeliveryOrder $order): array
    {
        return [
            'id' => (int) $order->getKey(),
            'number' => (string) $order->number,
            'status' => $order->status->value,
            'transport' => $this->transportLabel($order),
            'eta' => (int) $order->eta_minutes,
            'price_amount' => (int) $order->price_amount,
            'price_currency' => (string) $order->price_currency,
            'created_at' => $order->created_at->format((string) __('ui.formats.datetime')),
        ];
    }

    /**
     * Строка списка: набор полей OrderSummaryResource в текстовом виде.
     *
     * @param array{id: int, number: string, status: string, transport: string, eta: int, price_amount: int, price_currency: string, created_at: string} $item
     * @return array<string, string|HtmlString>
     */
    private function displayRow(array $item): array
    {
        // Номер — ссылка на карточку: доступное имя совпадает с номером,
        // зона нажатия не меньше 44 px на узком экране (FR-UI-126, FR-UI-183).
        $url = route('cabinet.orders.show', ['order' => $item['id']]);

        return [
            'number' => new HtmlString(Blade::render(
                '<x-app.link :href="$url" class="inline-flex min-h-11 items-center sm:min-h-0">{{ $number }}</x-app.link>',
                ['url' => $url, 'number' => $item['number']],
            )),
            // В карточке-строке узкого экрана то же действие подписано словами.
            'open' => new HtmlString(Blade::render(
                '<x-app.link :href="$url" class="inline-flex min-h-11 items-center">{{ $label }}</x-app.link>',
                ['url' => $url, 'label' => (string) __('ui.actions.open_order')],
            )),
            // Бейдж — тот же Blade-компонент, что в остальном интерфейсе.
            'status' => new HtmlString(Blade::render(
                '<x-app.status-badge :status="$status" />',
                ['status' => OrderStatus::from($item['status'])],
            )),
            'transport' => $item['transport'],
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
