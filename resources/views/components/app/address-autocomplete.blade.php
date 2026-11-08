@props([
    'name',
    'query' => '',
    'suggestions' => [],
    'wireModel' => null,
    'selectMethod' => null,
    'clearMethod' => null,
    'selected' => false,
    'placeholder' => null,
    'id' => null,
])

@aware([
    'hint' => null,
])

@php
    $errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $controlId = $id ?? $name;
    $hasError = $name !== null && $errorBag->has($name);
    $listboxId = $controlId.'-listbox';
    $describedBy = [];

    if (filled($hint)) {
        $describedBy[] = $controlId.'-hint';
    }

    if ($hasError) {
        $describedBy[] = $controlId.'-error';
    }

    $suggestionList = [];

    foreach ((array) $suggestions as $suggestion) {
        $suggestionList[] = [
            'id' => (int) ($suggestion['id'] ?? 0),
            'label' => (string) ($suggestion['label'] ?? ''),
        ];
    }

    $hasSuggestions = $suggestionList !== [];
    $queryText = trim((string) $query);
    // Выбранный адрес показывает свою подпись в поле, пустое состояние ему не нужно.
    $showEmptyState = ! $hasSuggestions && ! $selected && mb_strlen($queryText) >= 2;
    $showClearButton = $queryText !== '';
    $resolvedPlaceholder = $placeholder ?? __('ui.create_order.address_placeholder');
    $resolvedWireModel = $wireModel ?? $name;
    $resolvedSelectMethod = $selectMethod ?? 'select'.ucfirst((string) $name);
    $resolvedClearMethod = $clearMethod ?? 'clear'.ucfirst((string) $name);
@endphp

{{--
    Поле адреса с подсказками из справочника (FR-UI-121).

    Компонент владеет только полем ввода, списком подсказок, пустым
    состоянием и кнопкой очистки. Подпись, подсказка и текст ошибки
    остаются у <x-app.field>, поэтому компонент вызывается внутри него:
    id, aria-describedby и @error(name) продолжают работать.
--}}
<div
    {{ $attributes->class(['address-autocomplete'])->merge([
        'data-selected' => $selected ? 'true' : 'false',
        'wire:key' => 'address-autocomplete-'.$controlId,
        'x-data' => 'addressAutocomplete('.($hasSuggestions ? 'true' : 'false').')',
        'x-on:keydown' => 'onKeydown($event)',
        'x-on:focusout' => 'onFocusOut($event)',
    ]) }}
>
    <div class="address-autocomplete-control">
        <input
            x-ref="input"
            id="{{ $controlId }}"
            name="{{ $name }}"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            @if ($hasSuggestions)
                aria-controls="{{ $listboxId }}"
            @endif
            aria-expanded="{{ $hasSuggestions ? 'true' : 'false' }}"
            x-bind:aria-expanded="expanded ? 'true' : 'false'"
            x-bind:aria-activedescendant="activeOptionId"
            @if ($describedBy !== [])
                aria-describedby="{{ implode(' ', $describedBy) }}"
            @endif
            @if ($hasError)
                aria-invalid="true"
            @endif
            class="field-control address-autocomplete-input"
            placeholder="{{ $resolvedPlaceholder }}"
            value="{{ $query }}"
            wire:model.live.debounce.400ms="{{ $resolvedWireModel }}"
            x-on:input="onInput()"
            x-on:focus="undismiss()"
        />

        @if ($showClearButton)
            <button
                type="button"
                class="address-autocomplete-clear"
                wire:click="{{ $resolvedClearMethod }}"
                aria-label="{{ __('ui.create_order.address_clear') }}"
                x-on:click="focusInput()"
            >
                <span aria-hidden="true">&times;</span>
            </button>
        @endif
    </div>

    @if ($hasSuggestions)
        <div class="address-autocomplete-panel" x-show="! dismissed" x-cloak>
            {{-- Прокрутка длинной выдачи: единственная прокручиваемая область. --}}
            <x-app.scroll-area
                max-height="16rem"
                :label="__('ui.create_order.address_options')"
                class="address-autocomplete-scroll"
            >
                <div
                    id="{{ $listboxId }}"
                    role="listbox"
                    aria-label="{{ __('ui.create_order.address_options') }}"
                    class="address-autocomplete-list"
                >
                    @foreach ($suggestionList as $index => $suggestion)
                        <button
                            type="button"
                            role="option"
                            id="{{ $controlId }}-option-{{ $suggestion['id'] }}"
                            data-index="{{ $index }}"
                            wire:key="{{ $controlId }}-option-{{ $suggestion['id'] }}"
                            wire:click="{{ $resolvedSelectMethod }}({{ $suggestion['id'] }})"
                            class="address-autocomplete-option"
                            aria-selected="false"
                            x-bind:aria-selected="activeIndex === {{ $index }} ? 'true' : 'false'"
                            x-on:click="refocusOnEmpty = true"
                        >
                            <span class="address-autocomplete-label">{{ $suggestion['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            </x-app.scroll-area>
        </div>
    @endif

    @if ($showEmptyState)
        <div class="address-autocomplete-panel" x-show="! dismissed" x-cloak>
            <x-app.empty-state
                class="address-autocomplete-empty"
                role="status"
                :description="__('ui.create_order.addresses_empty')"
            />
        </div>
    @endif
</div>
