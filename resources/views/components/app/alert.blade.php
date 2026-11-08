@props([
    'variant' => 'info',
    'title' => null,
])

@php
    $roles = [
        'success' => 'status',
        'info' => 'status',
        'warning' => 'alert',
        'error' => 'alert',
    ];
    $role = $roles[$variant] ?? 'status';
    $live = $role === 'alert' ? 'assertive' : 'polite';
@endphp

<div
    {{ $attributes->class(['alert', 'alert-'.$variant])->merge([
        'role' => $role,
        'aria-live' => $live,
    ]) }}
>
    <div class="flex min-w-0 flex-col gap-1">
        @if (filled($title))
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
