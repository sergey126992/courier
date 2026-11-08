# TrackingLinkButton

## Назначение
Публичная ссылка на отслеживание заказа: создаёт подписанный адрес HTML-страницы, показывает URL и срок действия, поддерживает копирование в буфер с объявлением результата через `aria-live` (FR-UI-129, FR-UI-174).

## Расположение и вызов
- Код: `app/Livewire/Cabinet/TrackingLinkButton.php`
- Шаблон: `resources/views/livewire/cabinet/tracking-link-button.blade.php`
- Страница: `resources/views/cabinet/orders/tracking.blade.php`
- Алиас Livewire (штатный, от имени класса): `cabinet.tracking-link-button` — именно он используется в шаблонах; `cabinet.tracking-link` из ЧТЗ 8.2 не регистрируется.
- Вызов: `<livewire:cabinet.tracking-link-button :order-id="$order->id" />`

## Входные параметры и слоты
- `orderId` (`#[Locked]`): идентификатор заказа; модель между запросами не хранится.
- Свойства: `url` (готовая ссылка), `expiresAt` (срок в формате `ui.formats.datetime`), `error` (причина отказа).
- Слотов нет.

## Поведение и состояния
- Действие `create()`: `authorize('view', $order)` по `DeliveryOrderPolicy`, затем `App\Actions\Tracking\CreateTrackingLink` с именем маршрута из `config('ui.tracking.public_route')` (по умолчанию `tracking.public.web`).
- Подпись и срок ссылки не меняются: TTL — `config('tracking.link_ttl_hours')`; вызов операции без параметра маршрута по-прежнему даёт API-ссылку `/api/v1/tracking/{code}`.
- Успех: показ URL ссылкой в новой вкладке, срока действия и события `notify` с текстом `orders.messages.tracking_link_created`.
- Копирование: Alpine `navigator.clipboard.writeText`, результат объявляется через `role="status"` и `aria-live="polite"` текстом `tracking.messages.copied`.
- Если имя маршрута из конфигурации не объявлено, операция бросает `RouteNotFoundException`, и кнопка показывает текст `tracking.messages.link_unavailable` вместо падения страницы. Штатная ошибка не возникает: маршрут `tracking.public.web` объявлен.
- Анимаций нет: при `prefers-reduced-motion: reduce` поведение не меняется (FR-UI-177).

## Ограничения
- Не создаёт страницу `/tracking/{code}` и не хранит ссылки в базе: подпись stateless; страницу обслуживает маршрут `tracking.public.web` (карточка `ui/PublicTracking.md`).
- Не содержит бизнес-логики подписи и прав — только вызов операции после `authorize()`.
- Не обращается к базе из Blade и не выводит персональных данных.

## Где используется
- `resources/views/cabinet/orders/tracking.blade.php`

## Какие тесты покрывают
- `tests/Feature/Livewire/Cabinet/TrackingLinkTest.php` — ссылка на `tracking.public.web` с подписью и сроком из конфигурации, показ адреса и срока, API-ссылка при вызове операции без параметра, отсутствие записей в базе.
- `tests/Feature/Tracking/TrackingLinkTest.php` — неизменный контракт API-ссылки (без правок).
