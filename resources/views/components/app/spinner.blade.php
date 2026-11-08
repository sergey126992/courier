@props([
    'label' => null,
    'size' => 'md',
])

@php
    $resolvedLabel = $label ?? __('ui.states.loading');
    $sizeClass = match ($size) {
        'sm' => 'size-3.5',
        'lg' => 'size-6',
        default => null,
    };
@endphp

<span role="status" {{ $attributes->class(['inline-flex', 'items-center', 'gap-1.5']) }}>
    <span @class(['spinner', $sizeClass]) aria-hidden="true"></span>
    <span class="sr-only">{{ $resolvedLabel }}</span>
</span>
