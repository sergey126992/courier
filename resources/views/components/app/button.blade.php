@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'loading' => false,
    'disabled' => false,
])

@php
    $variantClass = match ($variant) {
        'secondary' => 'btn-secondary',
        'quiet', 'ghost' => 'btn-quiet',
        'danger' => 'btn-danger',
        default => 'btn-primary',
    };
    $sizeClass = $size === 'sm' ? 'btn-sm' : null;
    $isDisabled = $disabled || $loading;
@endphp

<button
    {{ $attributes->class(['btn', $variantClass, $sizeClass])->merge([
        'type' => $type,
        'disabled' => $isDisabled,
        'aria-busy' => $loading ? 'true' : null,
    ]) }}
>
    @if ($loading)
        <x-app.spinner :label="__('ui.states.loading')" size="sm" />
    @endif
    {{ $slot }}
</button>
