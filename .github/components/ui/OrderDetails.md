# OrderDetails

## Назначение
Карточка заказа отправителя: адреса забора и доставки, посылка, цена с разбивкой, ETA, транспорт, номер и код трекинга, история статусов с автором и причиной; отмена заказа до забора (FR-UI-126, 127).

## Расположение и вызов
- Код: `app/Livewire/Cabinet/OrderDetails.php`
- Шаблон: `resources/views/livewire/cabinet/order-details.blade.php`
- Страница: `resources/views/cabinet/orders/show.blade.php` (маршрут `cabinet.orders.show`, контроллер `App\Http\Controllers\Web\CabinetOrderController`)
- Вызов: `<livewire:cabinet.order-details :order-id="$order->id" />`

## Входные параметры и слоты
- `orderId` (`#[Locked]`): идентификатор заказа; модель между запросами не хранится.
- Свойства: `cancelReason` (причина, необязательна), `cancelling` (состояние выполнения), `error` (сообщение отказа), `status` (сообщение успеха).
- Слотов нет: карточка и история строятся компонентом.

## Поведение и состояния
- Чтение: `DeliveryOrder::query()->findOrFail($orderId)` + `loadMissing(['statusHistory.changedBy'])` — история и авторы загружаются заранее, N+1 нет.
- Доступ: `abort(404)` при отказе политики `view`, поэтому чужой заказ неотличим от несуществующего (FR-UI-119).
- Действие `cancel()`: `authorize('cancel', $order)` по `DeliveryOrderPolicy`, проверка причины правилами `CancelOrderRequest::rulesFor()`, затем `App\Actions\Order\CancelOrder`. Автор и причина попадают в историю доменным действием.
- Кнопка «Отменить заказ» показывается только при разрешённом переходе: `OrderTransitions::isAllowed($status, OrderStatus::Cancelled)` и политика `cancel` (FR-UI-127). Свой список статусов не используется.
- Подтверждение — существующий `<x-app.confirm-dialog>` с необязательным полем причины; подтверждение вызывает `$wire.cancel()`.
- Успех: событие `order-cancelled`, событие `notify` и сообщение `orders.messages.cancelled`; карточка перечитывает заказ без перезагрузки страницы, кнопка отмены исчезает.
- Конфликт (заказ уже забран или отменён): сообщение домена `ORDER_STATUS_TRANSITION_NOT_ALLOWED` с текущим статусом в алерте карточки; страница остаётся рабочей, статус перечитывается.
- Пустая история: текст `orders.labels.no_history`.
- Ссылка «Отслеживание» ведёт на маршрут `cabinet.orders.tracking` (`GET /cabinet/orders/{order}/tracking`, `App\Http\Controllers\Web\CabinetOrderController::tracking`) и выводится при `Route::has('cabinet.orders.tracking')`.
- Блок трекинга и публичная ссылка живут на отдельной странице, а не внутри карточки: карточка остаётся в бюджете «не более 5 SQL» и не запускает `wire:poll`.

## Ограничения
- Не обращается к базе из Blade: все значения, подписи и суммы готовятся в компоненте.
- Не содержит бизнес-логики переходов: допустимость отмены и запись истории — `OrderTransitions` и `CancelOrder`.
- Не выполняет запросы из шаблона и не проверяет роли вручную — доступ закрывают маршрут (`can:viewAny`) и политика.
- Не создаёт публичную ссылку и не показывает трекинг: блок живёт на отдельной странице `cabinet.orders.tracking` (компоненты `OrderTracking` и `TrackingLinkButton`), карточка только ссылается на неё.

## Где используется
- `resources/views/cabinet/orders/show.blade.php`

## Какие тесты покрывают
- `tests/Feature/Livewire/Cabinet/OrderDetailsTest.php` — отмена с причиной и без, запись истории, сообщение после забора без смены статуса, видимость кнопки по доменному правилу, история с автором.
- `tests/Feature/Web/CabinetOrderShowTest.php` — 200 владельцу, 302 гостю, 403 ролям без доступа, одинаковый 404 на чужой и несуществующий заказ, бюджет SQL (не более 5 запросов).
