@props([
    'variant' => 'info',
    'message' => null,
])

@php
    $variantClass = match ($variant) {
        'success' => 'border-success',
        'warning' => 'border-warning',
        'error' => 'border-error',
        default => 'border-info',
    };
@endphp

<div
    {{ $attributes->class(['toast', $variantClass])->merge([
        'role' => 'status',
        'aria-live' => 'polite',
        'data-variant' => $variant,
    ]) }}
>
    <span class="min-w-0">{{ $message ?? $slot }}</span>
</div>
