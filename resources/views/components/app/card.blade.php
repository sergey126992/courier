@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->class(['card']) }}>
    @if (filled($title) || isset($actions))
        <div class="card-header">
            <div class="min-w-0">
                @if (filled($title))
                    <h2 class="card-title">{{ $title }}</h2>
                @endif
                @if (filled($subtitle))
                    <p class="card-subtitle">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="card-actions">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="card-body">
        {{ $slot }}
    </div>
</div>
