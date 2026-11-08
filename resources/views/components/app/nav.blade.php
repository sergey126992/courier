@props([
    'items' => [],
    'orientation' => 'horizontal',
])

<nav
    {{ $attributes->class(['nav'])->merge([
        'aria-label' => __('ui.nav.label'),
        'data-orientation' => $orientation,
    ]) }}
>
    @foreach ($items as $item)
        <a
            class="nav-link"
            href="{{ $item['href'] ?? '#' }}"
            @if ((bool) ($item['active'] ?? false)) aria-current="page" @endif
        >{{ $item['label'] ?? '' }}</a>
    @endforeach
</nav>
