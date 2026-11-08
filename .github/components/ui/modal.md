# modal

## Назначение
Модальное окно с заголовком, прокручиваемым содержимым и необязательным
футером (FR-UI-173, 185).

## Расположение и вызов
- Код: `resources/views/components/app/modal.blade.php`
- Вызов:
  `<x-app.modal name="cancel-order" title="Отменить заказ?">…<x-slot:footer>…</x-slot:footer></x-app.modal>`

## Входные параметры и слоты
- `name`: идентификатор окна для событий `open-modal`/`close-modal`
- `title`: заголовок; связывается через `aria-labelledby`
- `open`: начальное состояние (по умолчанию закрыто)
- `slot`: содержимое внутри `<x-app.scroll-area>`
- `footer`: необязательные действия окна

## Поведение и состояния
- Корень — `role="dialog"`, `aria-modal="true"`,
  `aria-labelledby="<name>-title"`.
- Alpine `shellDialog`: Escape закрывает, фокус-ловушка на `Tab`/`Shift+Tab`,
  начальный фокус в окне, возврат фокуса на инициатора при закрытии.
- Открытие и закрытие — событиями `open-modal`/`close-modal`
  (`$event.detail === name`); подтверждение шлёт `dialog-confirmed`.
- Содержимое прокручивается только `<x-app.scroll-area>`; тело окна имеет
  минимальную высоту, поэтому появление сообщения не двигает рамку.
- Клик по подложке закрывает окно.

## Ограничения
- Бизнес-правил нет: окно не решает, можно ли выполнить действие.
- Тексты кнопок по умолчанию — из `lang/ru/ui.php`.

## Где используется
- `resources/views/components/app/confirm-dialog.blade.php`
- Страницы отмены заказа и назначения курьера (пункты 13 и 16 плана).

## Какие тесты покрывают
- `tests/Feature/Components/ShellComponentsTest.php` — `role="dialog"`,
  `aria-modal`, `aria-labelledby` и заголовок.
