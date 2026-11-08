<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Preview') · Карго</title>

    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('pv-theme');
                var theme = stored === 'light' || stored === 'dark'
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        }());
    </script>

    @vite(['resources/css/preview.css'])
</head>
<body class="pv-body">
    <header class="pv-shell-header">
        <a class="pv-shell-brand" href="{{ route('preview.index') }}">Preview</a>
        <span class="pv-shell-count">{{ count((array) config('preview.demos', [])) }} demo</span>
        <div class="pv-shell-end">
            <div class="pv-theme-toggle" role="group" aria-label="Тема оформления">
                <button type="button" class="pv-theme-option" data-theme-toggle data-theme-value="light" aria-pressed="true">Светлая</button>
                <button type="button" class="pv-theme-option" data-theme-toggle data-theme-value="dark" aria-pressed="false">Тёмная</button>
            </div>
        </div>
    </header>

    <main class="pv-shell-main">
        @yield('content')
    </main>

    <script>
        (function () {
            var root = document.documentElement;
            var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-toggle]'));

            function sync(theme) {
                buttons.forEach(function (button) {
                    button.setAttribute(
                        'aria-pressed',
                        button.getAttribute('data-theme-value') === theme ? 'true' : 'false'
                    );
                });
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var theme = button.getAttribute('data-theme-value');
                    root.setAttribute('data-theme', theme);
                    try {
                        window.localStorage.setItem('pv-theme', theme);
                    } catch (error) {}
                    sync(theme);
                });
            });

            sync(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
        }());
    </script>
</body>
</html>
