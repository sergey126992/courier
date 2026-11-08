@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class(['empty-state']) }}>
    @if (filled($title))
        <p class="empty-state-title">{{ $title }}</p>
    @endif

    @if (filled($description))
        <p class="empty-state-text">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="empty-state-action">{{ $action }}</div>
    @endisset
</div>
