# confirm-dialog

## Назначение
Окно подтверждения действия поверх обычного `modal`: заголовок, содержимое,
кнопки подтверждения и отмены (FR-UI-173, 185).

## Расположение и вызов
- Код: `resources/views/components/app/confirm-dialog.blade.php`
- Вызов:
  `<x-app.confirm-dialog name="cancel-order" title="Отменить заказ?" confirm-label="Отменить" />`

## Входные параметры и слоты
- `name`, `title`, `open`: передаются в `modal`
- `confirmLabel`: подпись подтверждения, по умолчанию `ui.actions.confirm`
- `cancelLabel`: подпись отмены, по умолчанию `ui.actions.cancel`
- `slot`: пояснение последствий действия

## Поведение и состояния
- Полностью наследует `role="dialog"`, `aria-modal`, `aria-labelledby`,
  фокус-ловушку, Escape и возврат фокуса от `modal`.
- Слой выше обычного окна: класс `modal-confirm` (`z-index` из
  `components/modals.css`).
- Подтверждение шлёт событие `dialog-confirmed` с именем окна и закрывает его;
  отмена закрывает без события.

## Ограничения
- Не выполняет действие сам: обработчик живёт на странице (Livewire/форма).
- Тексты по умолчанию — только из `lang/`.

## Где используется
- Страницы отмены заказа и назначения курьера (пункты 13 и 16 плана).

## Какие тесты покрывают
- `tests/Feature/Components/ShellComponentsTest.php` — диалог, класс
  `modal-confirm`, подписи подтверждения и отмены.
