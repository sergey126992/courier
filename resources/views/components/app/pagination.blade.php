@props([
    'hasMore' => false,
    'nextUrl' => null,
    'prevUrl' => null,
    'label' => null,
])

@php
    $navLabel = $label ?? __('ui.aria.pagination');
@endphp

<nav {{ $attributes->class(['pagination'])->merge(['aria-label' => $navLabel]) }}>
    @if (filled($prevUrl))
        <a class="btn btn-quiet" href="{{ $prevUrl }}" rel="prev">{{ __('ui.actions.back_to_start') }}</a>
    @endif

    @if ($hasMore && filled($nextUrl))
        <a class="btn btn-secondary" href="{{ $nextUrl }}" rel="next">{{ __('ui.actions.show_more') }}</a>
    @endif
</nav>
