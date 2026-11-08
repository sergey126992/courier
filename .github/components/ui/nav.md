# nav

## Назначение
Список ссылок основной навигации. Компонент представления: получает готовые
пункты и рисует их с `aria-current="page"` для активного (FR-UI-114…116).

## Расположение и вызов
- Код: `resources/views/components/app/nav.blade.php`
- Вызов: `<x-app.nav :items="$items" orientation="vertical" />`

## Входные параметры и слоты
- `items`: список пунктов `['href' => string, 'label' => string, 'active' => bool]`
- `orientation`: `horizontal` (по умолчанию) или `vertical` — вертикальный
  список используется в мобильном drawer

## Поведение и состояния
- Корень — `<nav aria-label="{{ __('ui.nav.label') }}">`.
- Активный пункт получает `aria-current="page"`; подчёркивание и цвет —
  токены `navigation.css`.
- Пустой список выводит пустой `<nav>`; пустое состояние не нужно.

## Ограничения
- Не решает, какие пункты доступны роли: состав готовит `<x-app.header>`.
- Не содержит ссылок с литеральными адресами.

## Где используется
- `resources/views/components/app/header.blade.php` — в шапке и в drawer.

## Какие тесты покрывают
- `tests/Feature/Components/ShellComponentsTest.php` — через шапку: пункты
  доступной роли.
