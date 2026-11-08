# tabs

## Назначение
Вкладки страницы с корректной семантикой `role="tablist"` и переключением без перезагрузки
(FR-UI-171, FR-UI-178): активная вкладка помечена `aria-selected`, панели связаны через `aria-controls`.

## Расположение и вызов
- Код: `resources/views/components/app/tabs.blade.php`, поведение — `resources/js/app.js`
- Вызов:
```blade
<x-app.tabs name="order" :active="$tab" :tabs="[
    'details' => __('orders.labels.number'),
    'history' => __('orders.labels.history'),
]">
    <div role="tabpanel" id="order-panel-details" aria-labelledby="order-tab-details">...</div>
    <div role="tabpanel" id="order-panel-history" aria-labelledby="order-tab-history" hidden>...</div>
</x-app.tabs>
```

## Входные параметры и слоты
- `tabs`: пары «идентификатор → подпись» или список `[['id' => ..., 'label' => ...]]`.
- `active`: идентификатор активной вкладки; по умолчанию первая.
- `name`: префикс идентификаторов `{name}-tab-{id}` и `{name}-panel-{id}`.
- Слот: панели с `role="tabpanel"` и указанными идентификаторами.

## Поведение и состояния
- Кнопка вкладки имеет `role="tab"`, `aria-controls` и `tabindex` 0/-1 (roving tabindex).
- Клик и клавиши `ArrowLeft`, `ArrowRight`, `Home`, `End` переключают вкладку и её панель.
- Активная вкладка помечена `aria-selected="true"` и подчёркиванием акцентом.
- Без JavaScript активная панель остаётся видимой, остальные скрыты атрибутом `hidden`.

## Ограничения
- Не загружает данные и не знает о содержимом панелей: их передаёт страница.
- Идентификаторы панелей должны совпадать с `aria-controls`, иначе переключение не сработает.
- Не используется как навигация между страницами — для этого `<x-app.nav>`.

## Где используется
- Карточка заказа (детали и история), панель диспетчера и профиль (пункты 9, 13, 15 плана M9).

## Какие тесты покрывают
- `tests/Feature/Components/LayoutComponentsTest.php` — `role="tablist"`, `aria-selected="true"`, `aria-controls` и `tabindex="-1"` у неактивной вкладки.
