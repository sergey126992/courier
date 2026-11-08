@props([
    'lines' => 1,
    'variant' => 'text',
])

@php
    $count = max(1, (int) $lines);
    $lineClass = match ($variant) {
        'block' => 'h-24 w-full',
        'circle' => 'size-10 rounded-full',
        default => 'h-3 w-full',
    };
@endphp

<div {{ $attributes->class(['flex', 'flex-col', 'gap-2']) }} aria-hidden="true">
    @for ($line = 1; $line <= $count; $line++)
        <span @class([
            'skeleton',
            $lineClass,
            'w-2/3' => $variant === 'text' && $count > 1 && $line === $count,
        ])></span>
    @endfor
</div>
