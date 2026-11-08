# OrderQueue

## Назначение
Очередь заказов диспетчера: только заказы в статусе «создан», новые сверху, курсорная выдача, фильтры зоны, периода создания и типа транспорта, автообновление без перезагрузки с паузой, пустое состояние (FR-UI-131…133, 177, 181, 182).

## Расположение и вызов
- Код: `app/Livewire/Dispatcher/OrderQueue.php`
- Шаблон: `resources/views/livewire/dispatcher/order-queue.blade.php`
- Страница: `resources/views/dispatcher/queue.blade.php` (маршрут `dispatcher.queue`, `GET /dispatcher`)
- Вызов: `<livewire:dispatcher.order-queue />`
- Доступ: middleware `web`, `auth`, `verified`, `can:assign,App\Models\Dispatch\Assignment` — диспетчер 200, отправитель, курьер и администратор 403, гость 302 на `login`.

## Входные параметры и слоты
- Параметров и слотов нет: выборку задают маршрут и адрес страницы.
- Свойства: `zoneId`, `from`, `to`, `transport` (фильтры), `paused` (пауза автообновления), `perPage`, `refreshSeconds`, `items` (накопленные строки), `nextCursor`, `hasMore`, `pages`, `failed`.
- Фильтры живут в адресе страницы: `#[Url]` у `zoneId` → `zone`, `from` → `from`, `to` → `to`, `transport` → `transport`. `paused` в адрес не попадает: после перезагрузки очередь снова обновляется.
- Вычисляемые: `rows` (строки для представления), `zoneOptions` (действующие зоны), `transportOptions` (значения `TransportType`), `orderCountLabel` (текст для `aria-live`).

## Поведение и состояния
- Чтение — только через `App\Services\Dispatch\DispatcherQueueQuery`: статус `created` задан внутри, фильтры зоны и транспорта — `where` поверх общего `OrderListQuery`, порядок «новые сверху» `(created_at, id)`, курсорная выдача. Справочник зон отдаёт тот же query-объект (`zones()`), поэтому строка не делает запрос на каждую зону.
- Фильтры проверяются правилами `App\Http\Requests\Dispatch\OrderQueueFilterRules`: период, размер порции и курсор — из общего `OrderFilterRules`, зона — `exists:service_zones,id`, транспорт — `Rule::enum(TransportType::class)`. Испорченные значения из адреса отбрасываются при открытии страницы, страница не падает.
- Действия: `applyFilters()` — проверка и выдача с начала, `loadMore()` — следующая порция по курсору, `togglePause()` — пауза и возобновление автообновления, `refresh()` — перечитать очередь с сохранением фильтров и числа загруженных порций (`pages`), во время паузы не выполняется.
- Автообновление: `wire:poll.{{ refreshSeconds }}s="refresh"` по `config('ui.dispatcher.refresh_seconds')`; обновляется только компонент очереди, поэтому открытое модальное окно страницы не «дёргается». Анимаций и автоскролла в шаблоне нет, поэтому при `prefers-reduced-motion: reduce` обновление без мигания (FR-UI-177).
- Пауза: при `paused` атрибут `wire:poll` не выводится вовсе — запросов нет; состояние объявлено текстом `dispatch.labels.paused` / `dispatch.labels.active` и кнопкой `ui.actions.pause_refresh` / `ui.actions.resume_refresh`.
- Количество заказов объявляется через `<p role="status" aria-live="polite">` с текстом `dispatch.labels.order_count`.
- Строка: номер, зона, транспорт (с видом лёгкого транспорта), вес, ETA, цена, время создания; длинные значения обрезаются классом `.list-value` с полным значением в `title`. Ссылка на карточку диспетчера выводится только при наличии маршрута `dispatcher.orders.show` (`Route::has`), иначе номер остаётся текстом.
- Узкий экран: `<x-app.data-list>` показывает карточки вместо таблицы, горизонтальной прокрутки нет.
- Пустая очередь — `<x-app.empty-state>` с текстом `ui.states.empty_queue_*` и действием «Обновить» (`applyFilters`, выполняется и во время паузы).
- Ошибка загрузки — `<x-app.alert variant="error">` и событие `notify` с типом `error`.

## Ограничения
- Не обращается к базе из Blade: все значения приходят параметрами и вычисляемыми свойствами (FR-UI-142, 194).
- Не назначает курьера и не открывает карточку заказа: это следующий экран панели (маршрут `dispatcher.orders.show`).
- Не хранит фильтры только в свойствах: значения восстанавливаются из адреса страницы.
- Не кэширует выборку: после действий и автообновления данные читаются заново.
- Не проверяет роли в компоненте: доступ закрывает маршрут.

## Где используется
- `resources/views/dispatcher/queue.blade.php`

## Какие тесты покрывают
- `tests/Feature/Livewire/Dispatcher/OrderQueueTest.php` — только `created`, новые сверху, фильтры зоны и транспорта, сохранение фильтра при `refresh`, пауза останавливает обновление, пустое состояние с «Обновить», объявление количества, бюджет SQL (не более 3 SELECT на рендер), интервал из конфигурации без `sleep`.
- `tests/Feature/Web/RoleAccessTest.php` — гость 302 на `login`, диспетчер 200, остальные роли 403, неподтверждённый аккаунт получает напоминание.
- `tests/Feature/Web/QueryBudgetTest.php`, `tests/Feature/Web/QueryObjectsEquivalenceTest.php` — бюджет и эквивалентность выборки очереди общему query-объекту.
