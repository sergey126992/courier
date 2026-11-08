# NotificationPreferences

## Назначение
Экран «Настройки уведомлений» кабинета: матрица «тип события × каналы», окно тихих
часов и привязка Telegram. Решает FR-UI-402…410: пользователь сам выбирает каналы
по событиям, задаёт окно тишины и привязывает чат по одноразовой ссылке.

## Расположение и вызов
- Код: `app/Livewire/Account/NotificationPreferences.php`
- Шаблон: `resources/views/livewire/account/notification-preferences.blade.php`
- Страница: `resources/views/account/notifications.blade.php`, маршрут `GET /account/notifications` (`account.notifications`, middleware `verified`)
- Вызов: `<livewire:account.notification-preferences />`

## Входные параметры и слоты
- Слотов и параметров нет: данные компонент читает сам при открытии экрана.
- `user_id` берётся из сессии монолита в каждом действии и свойством не становится (FR-NTF-461).

## Публичные свойства
- `rows`: строки матрицы `[{event_type, channels: {email, push, telegram}, source}]`; набор строк и отметки приходят из сервиса, роль передаётся ему параметром запроса (FR-NTF-460, FR-UI-408).
- `quietEnabled`, `quietFrom`, `quietTo`, `quietTimezone`: окно тихих часов; `quiet_hours` в сервисе хранится по строке предпочтения.
- `telegramStatus`: `absent`, `pending`, `verified` или `revoked`; `telegramVerifiedAt` — момент подтверждения в локальном формате.
- `deepLink`, `deepLinkExpiresAt`: одноразовая ссылка привязки и срок её действия (FR-UI-404).
- `serviceAvailable`: сервис ответил; при `false` форма не рендерится, доступна только кнопка повтора.
- `message`, `messageVariant`: результат последнего действия в области `aria-live`.

## Вычисляемые свойства
- `eventTypes`: строки матрицы с подписями типов событий и каналов из `lang/ru/ui.php`.
- `timezoneOptions`: список `DateTimeZone::listIdentifiers()` для выбора часового пояса.

## Действия
- `save`: проверка полей, затем `PATCH /api/v1/preferences` через сервис; ошибка окна выводится `@error` у поля.
- `startTelegramBinding`: выпускает deep-link, показывает ссылку и срок, статус становится `pending`.
- `refreshBinding`: повторно читает статус привязки, страница не перезагружается.
- `unbindTelegram`: вызывается только после подтверждения в `<x-app.modal>` (`dialog-confirmed`), статус возвращается в `absent`.
- `reload`: повторное чтение экрана, когда сервис не ответил.

## Поведение и состояния
- Состояния экрана: загружено; сервис недоступен (`<x-app.alert variant="error">` и кнопка «Обновить», изменяющих действий нет); пустой набор типов событий у диспетчера и администратора (только тихие часы и привязка).
- Ошибки: поля окна тихих часов — `@error` у поля (`<x-app.field>`), коды отказа сервиса — безопасный текст в alert без внутреннего сообщения.
- Клавиатура: чекбоксы и поля — нативные элементы в естественном порядке табов, модалка удерживает фокус и закрывается по Escape.
- Mobile-first: на ширине меньше `md` строки матрицы идут карточками в одну колонку, горизонтальной прокрутки нет; прокрутка есть только у `scroll-area` модального окна.
- Обе темы: цвета только токенами, статус привязки различается подписью и тоном бейджа.

## Ограничения
- Бизнес-правил матрицы в компоненте нет: значения и допустимые типы событий приходят из сервиса (`GET/PATCH /api/v1/preferences?role=`), монолит их не хранит, не кэширует и не держит перечня «роль → типы событий» (FR-OPS-405, FR-NTF-460).
- Браузер не обращается к сервису напрямую: все вызовы идут через `NotificationPreferencesScreen` и `NotificationServiceClient` с сервисным токеном (FR-UI-406).
- `chat_id`, адрес сервиса и сервисный токен не попадают в свойства и разметку (FR-OPS-406, FR-OPS-412).
- Abilities токенов не расширяются: доступ проверяет только middleware `verified`.

## Где используется
- `resources/views/account/notifications.blade.php` — страница настроек.
- `resources/views/account.blade.php` — карточка-ссылка входа на экран (FR-UI-411).

## Какие тесты покрывают
- `tests/Feature/Livewire/Account/NotificationPreferencesTest.php` — матрица по ролям, сохранение матрицы и тихих часов, `@error` у поля, недоступность сервиса, привязка и отвязка Telegram, отсутствие `chat_id` и прокрутки.
- `tests/Feature/Web/NotificationPreferencesScreenTest.php` — доступ `verified`, карточка профиля, предупреждение при недоступном сервисе, отсутствие адреса сервиса и токена в разметке.
- `tests/Feature/Notifications/NotificationServiceClientTest.php` — прокси-методы предпочтений (с `role`) и привязок.
- `tests/Arch/NotificationPreferencesGuardTest.php` — в монолите нет дубликата реестра событий и перечня «роль → типы событий».
