# theme-toggle

## Назначение
Переключатель темы из трёх значений: «Светлая», «Тёмная», «Как в системе»
(FR-UI-154, 155, 157).

## Расположение и вызов
- Код: `resources/views/components/app/theme-toggle.blade.php`
- Вызов: `<x-app.theme-toggle />` (`value` — для тестов и явного значения)

## Входные параметры и слоты
- `value`: необязательное значение `light|dark|system`; без него читается
  cookie `config('ui.theme.cookie')`, затем `config('ui.theme.default')`

## Поведение и состояния
- Группа кнопок `role="group"` с подписью `ui.theme.label`; выбранная кнопка
  помечена `aria-pressed="true"`, остальные — `"false"`.
- Alpine `themeToggle` сразу выставляет `data-theme` (`light`/`dark`),
  разрешая `system` через `prefers-color-scheme`.
- Выбор сохраняется в cookie `ui_theme` и в `localStorage`; сервер читает
  cookie и ставит тему до отрисовки, поэтому перезагрузка не мигает.
- Переключение не перезагружает страницу и не меняет вёрстку.

## Ограничения
- Alpine отвечает только за локальное состояние темы; токены и цвета живут
  в `resources/css/tokens.css`.
- Литеральных цветов и текстов в компоненте нет.

## Где используется
- `resources/views/components/app/header.blade.php`.

## Какие тесты покрывают
- `tests/Feature/Components/ShellComponentsTest.php` — `role="group"`,
  `aria-pressed="true"` и три значения переключателя.
