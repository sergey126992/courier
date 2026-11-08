<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/*
|--------------------------------------------------------------------------
| Компонент поля адреса с подсказками (FR-UI-121)
|--------------------------------------------------------------------------
|
| Проверяется только представление: связь комбобокса со списком, подсказки
| как кнопки, пустая выдача, прокрутка длинного списка, доступное имя
| кнопки очистки и связь поля с подписью и ошибкой. База и сеть не нужны.
|
*/

/**
 * Публикует ошибки валидации в общий набор ошибок представлений.
 *
 * @param array<string, string> $messages
 */
function shareAddressAutocompleteErrors(array $messages): void
{
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag($messages)));
}

/**
 * Подсказки: идентификатор записи справочника и видимая подпись адреса.
 *
 * @return list<array{id: int, label: string}>
 */
function addressAutocompleteSuggestions(): array
{
    return [
        ['id' => 12, 'label' => 'Москва, Тверская улица, 7 (Тверской район)'],
        ['id' => 34, 'label' => 'Москва, Тверская улица, 9 (Тверской район)'],
    ];
}

it('связывает поле-комбобокс со списком подсказок', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="$suggestions" '
        .'wire-model="pickupQuery" select-method="selectPickup" clear-method="clearPickup" />',
        ['suggestions' => addressAutocompleteSuggestions()],
    )->__toString();

    expect($html)
        ->toContain('role="combobox"')
        ->toContain('aria-autocomplete="list"')
        ->toContain('autocomplete="off"')
        ->toContain('aria-expanded="true"')
        ->toContain('aria-controls="pickup-listbox"')
        ->toContain('role="listbox"')
        ->toContain('id="pickup-listbox"')
        ->toContain('aria-label="'.__('ui.create_order.address_options').'"')
        ->toContain('wire:model.live.debounce.400ms="pickupQuery"')
        ->toContain('placeholder="'.__('ui.create_order.address_placeholder').'"');
});

it('выводит подсказки кнопками с подписями и методом выбора', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="$suggestions" '
        .'select-method="selectPickup" clear-method="clearPickup" />',
        ['suggestions' => addressAutocompleteSuggestions()],
    )->__toString();

    expect($html)
        ->toContain('role="option"')
        ->toContain('type="button"')
        ->toContain('wire:click="selectPickup(12)"')
        ->toContain('wire:click="selectPickup(34)"')
        ->toContain('id="pickup-option-12"')
        ->toContain('Москва, Тверская улица, 7 (Тверской район)')
        ->toContain('Москва, Тверская улица, 9 (Тверской район)');
});

it('показывает пустое состояние при непустой строке без совпадений', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="dropoff" query="Несуществующий адрес" :suggestions="[]" />',
    )->__toString();

    expect($html)
        ->toContain(__('ui.create_order.addresses_empty'))
        ->toContain('role="status"')
        ->toContain('empty-state');

    expect(str_contains($html, 'role="listbox"'))->toBeFalse();
});

it('не показывает пустое состояние на короткой строке', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="dropoff" query="Н" :suggestions="[]" />',
    )->__toString();

    expect(str_contains($html, __('ui.create_order.addresses_empty')))->toBeFalse();
});

it('не показывает пустое состояние у выбранного адреса', function (): void {
    $selected = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Москва, Тверская улица, 7" :suggestions="[]" selected />',
    )->__toString();

    $unselected = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Москва, Тверская улица, 7" :suggestions="[]" />',
    )->__toString();

    expect(str_contains($selected, __('ui.create_order.addresses_empty')))->toBeFalse()
        ->and(str_contains($unselected, __('ui.create_order.addresses_empty')))->toBeTrue();
});

it('прокручивает длинный список подсказок через область прокрутки', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="$suggestions" />',
        ['suggestions' => addressAutocompleteSuggestions()],
    )->__toString();

    expect($html)
        ->toContain('scroll-area')
        ->toContain('overflow-y-auto')
        ->toContain('max-height: 16rem')
        ->toContain('role="region"')
        ->toContain('aria-label="'.__('ui.create_order.address_options').'"');
});

it('даёт кнопке очистки доступное имя и метод родителя', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="[]" clear-method="clearPickup" />',
    )->__toString();

    expect($html)
        ->toContain('class="address-autocomplete-clear"')
        ->toContain('type="button"')
        ->toContain('aria-label="'.__('ui.create_order.address_clear').'"')
        ->toContain('wire:click="clearPickup"');
});

it('не выводит висячие aria-controls и кнопку очистки для пустого поля', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="" :suggestions="[]" clear-method="clearPickup" />',
    )->__toString();

    expect(str_contains($html, 'role="listbox"'))->toBeFalse()
        ->and(str_contains($html, 'aria-controls'))->toBeFalse()
        ->and(str_contains($html, 'aria-expanded="false"'))->toBeTrue()
        ->and(str_contains($html, 'address-autocomplete-clear'))->toBeFalse()
        ->and(str_contains($html, __('ui.create_order.address_clear')))->toBeFalse();
});

it('показывает кнопку очистки, когда в поле есть текст', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="[]" clear-method="clearPickup" />',
    )->__toString();

    expect($html)
        ->toContain('address-autocomplete-clear')
        ->toContain('aria-label="'.__('ui.create_order.address_clear').'"')
        ->toContain('wire:click="clearPickup"');
});

it('выводит aria-controls только вместе с отрендеренным списком подсказок', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="$suggestions" />',
        ['suggestions' => addressAutocompleteSuggestions()],
    )->__toString();

    expect($html)
        ->toContain('aria-controls="pickup-listbox"')
        ->toContain('id="pickup-listbox"')
        ->toContain('role="listbox"')
        ->toContain('aria-expanded="true"');
});

it('связывает поле с подписью и с ошибкой через aria-describedby', function (): void {
    shareAddressAutocompleteErrors(['pickup' => 'Выберите адрес из справочника']);

    $html = $this->blade(
        '<x-app.field name="pickup" label="Откуда забрать" hint="Адрес из справочника">'
        .'<x-app.address-autocomplete name="pickup" query="" :suggestions="[]" />'
        .'</x-app.field>',
    )->__toString();

    expect($html)
        ->toContain('for="pickup"')
        ->toContain('id="pickup"')
        ->toContain('aria-describedby="pickup-hint pickup-error"')
        ->toContain('aria-invalid="true"')
        ->toContain('Выберите адрес из справочника');
});

it('даёт разным полям и подсказкам собственные wire:key', function (): void {
    $html = $this->blade(
        '<x-app.address-autocomplete name="pickup" query="Твер" :suggestions="$suggestions" />'
        .'<x-app.address-autocomplete name="dropoff" query="Твер" :suggestions="$suggestions" />',
        ['suggestions' => addressAutocompleteSuggestions()],
    )->__toString();

    expect($html)
        ->toContain('wire:key="address-autocomplete-pickup"')
        ->toContain('wire:key="address-autocomplete-dropoff"')
        ->toContain('wire:key="pickup-option-12"')
        ->toContain('wire:key="pickup-option-34"')
        ->toContain('wire:key="dropoff-option-12"')
        ->toContain('wire:key="dropoff-option-34"');
});
