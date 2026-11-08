@props([
    'href' => '#',
    'variant' => 'default',
    'target' => null,
    'rel' => null,
])

@php
    $isExternal = \Illuminate\Support\Str::startsWith((string) $href, ['http://', 'https://', '//', 'mailto:', 'tel:']);
    $resolvedTarget = $target ?? ($isExternal ? '_blank' : null);
    $resolvedRel = $rel ?? ($resolvedTarget === '_blank' ? 'noopener noreferrer' : null);
    $variantClasses = $variant === 'quiet'
        ? ['text-muted', 'hover:underline', 'underline-offset-2']
        : ['underline', 'decoration-1', 'underline-offset-2'];
@endphp

<a
    href="{{ $href }}"
    {{ $attributes->class([...$variantClasses, 'rounded-sm', 'focus-visible:outline-2', 'focus-visible:outline-offset-2', 'focus-visible:outline-focus'])->merge([
        'target' => $resolvedTarget,
        'rel' => $resolvedRel,
    ]) }}
>{{ $slot }}</a>
