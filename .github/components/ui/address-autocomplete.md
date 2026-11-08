# address-autocomplete

## Назначение
Поле адреса с подсказками из справочника адресов (FR-UI-121): комбобокс внутри `<x-app.field>`,
список вариантов, пустое состояние и кнопка очистки. Компонент только показывает данные,
которые готовит Livewire-родитель.

## Расположение и вызов
- Код: `resources/views/components/app/address-autocomplete.blade.php`
- Поведение в браузере: `resources/js/app.js` — Alpine-компонент `addressAutocomplete`
- Стили: `resources/css/components/forms.css` — классы `.address-autocomplete*`
- Вызов:
```blade
<x-app.field name="pickup" :label="__('ui.create_order.pickup')" :hint="__('ui.create_order.address_hint')" required>
    <x-app.address-autocomplete
        name="pickup"
        :query="$pickupQuery"
        :suggestions="$pickupSuggestions"
        wire-model="pickupQuery"
        select-method="selectPickup"
        clear-method="clearPickup"
        :selected="filled($pickupAddressId)"
    />
</x-app.field>
```

## Входные параметры и слоты
- `name`: имя поля и основа `id`; по нему же связаны подпись, ошибка и правило валидации.
- `query`: текущая строка поиска — значение элемента ввода.
- `suggestions`: список подсказок; из каждого элемента используются только `id` и `label`.
- `wireModel`: свойство Livewire для `wire:model.live.debounce.400ms`; по умолчанию равно `name`.
- `selectMethod`: метод родителя для выбора подсказки; по умолчанию `select` + `ucfirst(name)`.
- `clearMethod`: метод родителя для кнопки очистки; по умолчанию `clear` + `ucfirst(name)`.
- `selected`: признак выбранного адреса; выключает пустое состояние.
- `placeholder`: подсказка в поле; по умолчанию `ui.create_order.address_placeholder`.
- `id`: переопределяет `id` элемента; по умолчанию равен `name`.
- Слота нет. Подсказка поля приходит из `<x-app.field>` через `@aware` и попадает в `aria-describedby`.

## Поведение и состояния
- Список: при непустом `suggestions` рендерится панель с `role="listbox"`; каждый вариант — `<button type="button" role="option">` с `wire:click="<selectMethod>(id)"` и собственным `wire:key`.
- Прокрутка длинной выдачи — только через `<x-app.scroll-area max-height="16rem">`; своих классов прокрутки компонент не добавляет.
- Пустое состояние: `x-app.empty-state` (`role="status"`, текст `ui.create_order.addresses_empty`) выводится, когда подсказок нет, в строке 2+ символа и адрес не выбран; на короткой строке и у выбранного адреса его нет.
- Кнопка очистки: видна при непустой строке, вызывает `wire:click="<clearMethod>"`, имеет доступное имя `ui.create_order.address_clear` и возвращает фокус в поле.
- Ошибка: при ошибке поля элемент получает `aria-invalid="true"`, а `aria-describedby` — `{id}-error`.
- Фокус: `focusout` закрывает список только при уходе фокуса за пределы компонента; выбор подсказки мышью возвращает фокус в поле.
- Синхронизация с Livewire: `MutationObserver` пересчитывает варианты после перерисовки DOM, поэтому выделение, `aria-expanded` и `aria-activedescendant` не расходятся с фактическим списком.

## Доступность
- Поле объявлено как `role="combobox"` с `aria-autocomplete="list"`, `aria-expanded` и `aria-activedescendant`.
- `aria-controls` на listbox выводится только вместе с отрисованным списком: висячего идентификатора у пустого поля нет.
- Клавиатура: ↑/↓ двигают активную подсказку по кругу, Home/End — к первой и последней, Enter выбирает активную подсказку из поля (на самой подсказке Enter срабатывает штатно), Escape закрывает список и возвращает фокус в поле, Tab уходит из компонента и закрывает список через `focusout`.
- Подсказки — настоящие кнопки, поэтому Tab к ним и выбор мышью работают без JavaScript.

## Ограничения
- Только представление: не ищет адреса, не обращается к базе и не хранит выбор — поиск и запись идентификатора выполняет Livewire-родитель (`App\Livewire\Cabinet\CreateOrder`).
- Не решает, какой адрес допустим: действующую запись проверяет родитель.
- Не выводит подпись, подсказку и текст ошибки — для этого его вызывают внутри `<x-app.field>`.
- Прокрутка — только `x-app.scroll-area`; `overflow-*` классы и inline `overflow` в компоненте запрещены (FR-UI-144).
- Тексты — из `lang/ru`; цвета — только токены темы (FR-UI-151).

## Где используется
- `resources/views/livewire/cabinet/create-order.blade.php` — адреса забора и доставки.

## Какие тесты покрывают
- `tests/Feature/Components/AddressAutocompleteComponentTest.php` — связь combobox с listbox, подсказки-кнопки с методом выбора, пустое состояние и его отсутствие на короткой строке и у выбранного адреса, прокрутка через `scroll-area`, кнопка очистки, отсутствие висячего `aria-controls`, `aria-describedby` с подсказкой и ошибкой, уникальные `wire:key`.
