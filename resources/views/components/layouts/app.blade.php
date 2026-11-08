@props([
    'title' => null,
])

@php
    $themeCookie = config('ui.theme.cookie');
    $cookie = request()->cookie($themeCookie);
    $default = (string) config('ui.theme.default');

    $explicit = in_array($cookie, ['light', 'dark'], true) ? $cookie : null;
    $resolveFromSystem = $explicit === null;
    $serverTheme = $explicit ?? (in_array($default, ['light', 'dark'], true) ? $default : 'light');
    $pageTitle = filled($title) ? $title.' — '.config('ui.name') : config('ui.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', (string) config('ui.locale')) }}" data-theme="{{ $serverTheme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $pageTitle }}</title>

        @if ($resolveFromSystem)
            {{-- FR-UI-155: выбранное «как в системе» разрешается до отрисовки содержимого. --}}
            <script>
                (function () {
                    var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
                })();
            </script>
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Livewire делит Alpine с бандлом app.js, поэтому скрипт не внедряется. --}}
        @livewireStyles
    </head>
    <body class="flex min-h-screen flex-col bg-canvas text-text">
        <x-app.header :user="auth()->user()" />

        <main id="main" class="mx-auto w-full max-w-5xl flex-1 px-4 py-6">
            {{ $slot }}
        </main>

        <footer class="border-t border-subtle">
            <p class="mx-auto w-full max-w-5xl px-4 py-4 text-sm text-muted">
                {{ config('ui.name') }} · {{ __('ui.brand.tagline') }}
            </p>
        </footer>

        @livewireScriptConfig
    </body>
</html>
