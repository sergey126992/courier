@props([
    'name',
    'title' => null,
    'open' => false,
])

@php
    $titleId = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $name).'-title';
@endphp

<div
    x-data="shellDialog(@js($name), @js((bool) $open))"
    x-show="open"
    x-cloak
    x-on:open-modal.window="if ($event.detail === name) show()"
    x-on:close-modal.window="if ($event.detail === name) close()"
    x-on:keydown.escape.window="close()"
    {{ $attributes->class(['modal'])->merge([
        'role' => 'dialog',
        'aria-modal' => 'true',
        'aria-labelledby' => $titleId,
    ]) }}
    x-on:click.self="close()"
>
    <div
        class="modal-panel"
        x-ref="panel"
        tabindex="-1"
        x-on:keydown.tab="trap($event)"
        x-on:click.stop
    >
        <div class="modal-header">
            <h2 class="modal-title" id="{{ $titleId }}">{{ $title }}</h2>

            <button
                type="button"
                class="btn btn-quiet btn-sm"
                x-on:click="close()"
                aria-label="{{ __('ui.actions.cancel') }}"
            ><span aria-hidden="true">&times;</span></button>
        </div>

        <x-app.scroll-area class="modal-body">{{ $slot }}</x-app.scroll-area>

        @isset($footer)
            <div class="modal-footer">{{ $footer }}</div>
        @endisset
    </div>
</div>
