# OrderTracking

## Назначение
Блок отслеживания заказа в кабинете: статус, последняя позиция, зафиксированный ETA, остаток пути с источником (`live`/`planned`) и последние события; автообновление по интервалу конфигурации (FR-UI-128, FR-UI-177).

## Расположение и вызов
- Код: `app/Livewire/Cabinet/OrderTracking.php`
- Шаблон: `resources/views/livewire/cabinet/order-tracking.blade.php`
- Страница: `resources/views/cabinet/orders/tracking.blade.php` (маршрут `cabinet.orders.tracking`, контроллер `App\Http\Controllers\Web\CabinetOrderController::tracking`)
- Вызов: `<livewire:cabinet.order-tracking :order-id="$order->id" />`

## Входные параметры и слоты
- `orderId` (`#[Locked]`): идентификатор заказа; модель между запросами не хранится.
- `refreshSeconds`: интервал `wire:poll` в секундах из `config('ui.tracking.refresh_seconds')` (env `UI_TRACKING_REFRESH_SECONDS`, по умолчанию 30).
- Слотов нет: блоки строятся компонентом.

## Поведение и состояния
- Чтение: только `App\Actions\Tracking\ReadOrderTracking` — статус, последняя позиция, зафиксированный прогноз, остаток пути, лента точек (лимит — `config('ui.pagination.per_page')`).
- Источник ETA не угадывается: `computed` — «Текущие данные», `planned` — «Расчётные данные»; при недоступном провайдере расстояний операция возвращает `planned`, страница остаётся рабочей (FR-TRK-124).
- Доступ: `abort(404)` при отказе политики `view`, поэтому чужой заказ неотличим от несуществующего (FR-UI-119).
- Действие `refresh()` сбрасывает снимок, и `wire:poll.<refreshSeconds>s="refresh"` перечитывает данные без перезагрузки.
- Последние события — через `<x-app.scroll-area>`; пустая лента — `<x-app.empty-state>` с текстами `ui.states.empty_tracking_*`.
- Анимаций и переходов в блоке нет: при `prefers-reduced-motion: reduce` обновление не сопровождается миганием (FR-UI-177).

## Ограничения
- Не обращается к базе из Blade: все значения и подписи готовятся в компоненте.
- Не пересчитывает зафиксированный ETA заказа: остаток пути отдаётся отдельным полем (FR-TRK-125).
- Не содержит формы и изменяющих действий: только чтение и периодическое обновление.
- Не заменяет публичную страницу трекинга `/tracking/{code}` — её создаёт отдельный маршрут.

## Где используется
- `resources/views/cabinet/orders/tracking.blade.php`

## Какие тесты покрывают
- `tests/Feature/Livewire/Cabinet/OrderTrackingTest.php` — источник `planned` при недоступном провайдере, источник `live` и остаток пути при доступном, обновление по `refresh()`, объявление `wire:poll` по конфигурации, пустое состояние событий.
- `tests/Feature/Web/CabinetOrderTrackingTest.php` — доступ владельца, 302 гостю, 403 ролям без доступа, одинаковый 404 на чужой и несуществующий заказ.
