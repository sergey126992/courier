@props([
    'value' => null,
])

@php
    $cookieName = (string) config('ui.theme.cookie');
    $default = (string) config('ui.theme.default');
    $current = $value ?? request()->cookie($cookieName);

    if (! in_array($current, ['light', 'dark', 'system'], true)) {
        $current = in_array($default, ['light', 'dark', 'system'], true) ? $default : 'system';
    }

    $options = [
        'light' => __('ui.theme.light'),
        'dark' => __('ui.theme.dark'),
        'system' => __('ui.theme.system'),
    ];
@endphp

<div
    {{ $attributes->class(['theme-toggle'])->merge([
        'role' => 'group',
        'aria-label' => __('ui.theme.label'),
        'x-data' => "themeToggle('".$current."', '".$cookieName."')",
    ]) }}
>
    @foreach ($options as $option => $label)
        <button
            type="button"
            class="theme-option"
            data-theme-value="{{ $option }}"
            aria-pressed="{{ $current === $option ? 'true' : 'false' }}"
            x-on:click="set('{{ $option }}')"
            x-bind:aria-pressed="theme === '{{ $option }}' ? 'true' : 'false'"
        >{{ $label }}</button>
    @endforeach
</div>
