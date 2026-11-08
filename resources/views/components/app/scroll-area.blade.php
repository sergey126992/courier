@props([
    'maxHeight' => '24rem',
    'label' => null,
])

@php
    $regionLabel = $label ?? __('ui.aria.scroll_area');
@endphp

<div
    {{ $attributes->class(['scroll-area', 'overflow-y-auto'])->merge([
        'tabindex' => '0',
        'role' => 'region',
        'aria-label' => $regionLabel,
        'style' => filled($maxHeight) ? 'max-height: '.$maxHeight : null,
    ]) }}
>
    {{ $slot }}
</div>
