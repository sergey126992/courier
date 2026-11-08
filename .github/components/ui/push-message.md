# push-message

## Назначение
Сообщение фейкового push-провайдера на странице симулятора: идентификатор
уведомления, тело сообщения и момент записи. Email и пользовательские данные
не выводятся (FR-NTF-254, ЧТЗ 8.2).

## Расположение и вызов
- Код: `resources/views/components/app/push-message.blade.php`
- Стили: `resources/css/components/notifications.css` (`.push-message*`)
- Вызов:
```blade
<li>
    <x-app.push-message :message="$row" />
</li>
```

## Входные параметры и слоты
- `message`: `array{id, notification_id, body, sent_at_label}`. Данные готовит
  `App\Services\Notifications\NotificationAdminJournal` из локального журнала
  или из API сервиса.
- Слота нет.

## Поведение и состояния
- Тело сообщения сохраняет переводы строк (`white-space: pre-line`) и ломается
  по словам, чтобы длинный текст не растягивал страницу.
- Метаданные — приглушённым цветом токена темы; обе темы поддерживаются.
- Прокрутку задаёт `<x-app.scroll-area>` страницы.

## Ограничения
- Не читает базу, не подписывается на события и не отправляет push.
- Не показывает адреса, device-токены и получателя.

## Где используется
- Страница симулятора push (`admin.notifications.push-simulator`).

## Какие тесты покрывают
- `tests/Feature/Web/NotificationsAdminTest.php` — сообщения из локального
  журнала и из сервиса, отсутствие email получателя.
- `tests/Arch/WebArchitectureTest.php` — карточка компонента.

