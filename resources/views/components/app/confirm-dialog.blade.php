@props([
    'name',
    'title' => null,
    'open' => false,
    'confirmLabel' => null,
    'cancelLabel' => null,
])

@php
    $confirmText = $confirmLabel ?? __('ui.actions.confirm');
    $cancelText = $cancelLabel ?? __('ui.actions.cancel');
@endphp

<x-app.modal :name="$name" :title="$title" :open="$open" class="modal-confirm">
    {{ $slot }}

    <x-slot:footer>
        <x-app.button variant="ghost" type="button" x-on:click="close()">{{ $cancelText }}</x-app.button>
        <x-app.button variant="primary" type="button" data-confirm x-on:click="confirm()">{{ $confirmText }}</x-app.button>
    </x-slot:footer>
</x-app.modal>
