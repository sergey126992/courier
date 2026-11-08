# notification-row

## Назначение
Строка журнала необработанных уведомлений администратора: номер записи, событие,
канал, состояние, число попыток, код причины и момент создания. Персональных
данных и токенов в строке нет (ЧТЗ 8.2, FR-NTF-275).

## Расположение и вызов
- Код: `resources/views/components/app/notification-row.blade.php`
- Стили: `resources/css/components/notifications.css` (`.notification-row*`, `.journal-list`)
- Вызов:
```blade
<li>
    <x-app.notification-row :row="$row">
        <x-slot:action>
            <x-app.button variant="secondary" size="sm" type="button" wire:click="retry({{ $row['id'] }})">
                {{ __('ui.actions.retry') }}
            </x-app.button>
        </x-slot:action>
    </x-app.notification-row>
</li>
```

## Входные параметры и слоты
- `row`: `array{id, channel, channel_label, status, status_label, attempts,
  error_code, event_type, created_at_label}`. Готовит
  `App\Services\Notifications\NotificationAdminJournal`; подписи уже локализованы.
- Слот `action`: кнопка повтора. Без слота строка только для чтения.

## Поведение и состояния
- Состояние показывается `<x-app.status-badge>`: слово плюс тон
  (`queued` — предупреждение, `sent` — успех, `failed`/`error` — ошибка).
- `error_code` и `created_at_label` выводятся только при наличии значения.
- Адаптивность: на узкой ширине метаданные переносятся на новую строку,
  длинные значения ломаются по словам.
- Прокрутку задаёт родительский `<x-app.scroll-area>`; сама строка не скроллится.

## Ограничения
- Не читает базу и не вызывает сервис: данные приходят готовым массивом.
- Не показывает email, телефон, имя получателя и текст сообщения.

## Где используется
- `livewire.admin.notification-journal` — список необработанных.

## Какие тесты покрывают
- `tests/Feature/Web/NotificationsAdminTest.php` — строки журнала в двух режимах,
  доступность кнопки повтора и ответы `202/404/409/503`.
- `tests/Arch/WebArchitectureTest.php` — карточка компонента и отсутствие
  кириллических литералов в шаблоне.
