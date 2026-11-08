<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Guard-тест оформления: цвета живут только в токенах
|--------------------------------------------------------------------------
|
| FR-UI-151, FR-UI-153: в боевых стилях и Blade-шаблонах нет литеральных
| цветов (#hex, rgb(), hsl(), oklch()) и палитровых утилит Tailwind.
| Исключения: resources/css/tokens.css (единственное место литералов) и
| preview-контур (resources/css/preview.css, resources/views/preview/**),
| который остаётся локальным эталоном, а не боевым экраном.
|
*/

const UI_STYLE_LITERAL_COLOR_PATTERN = '/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b|\brgba?\(|\bhsla?\(|\boklch\(/';

const UI_STYLE_PALETTE_UTILITY_PATTERN = '/\b(?:bg|text|border|ring|outline|fill|stroke|divide|placeholder|accent|shadow|from|via|to|decoration)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|white|black)(?:-\d{2,3})?\b/';

/**
 * Возвращает список найденных нарушений в виде "путь:строка".
 *
 * @return list<string>
 */
function uiStyleViolations(string $path, string|false $contents): array
{
    if ($contents === false) {
        return [];
    }

    $violations = [];

    foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
        if (preg_match(UI_STYLE_LITERAL_COLOR_PATTERN, $line) === 1
            || preg_match(UI_STYLE_PALETTE_UTILITY_PATTERN, $line) === 1) {
            $violations[] = sprintf('%s:%d', $path, $index + 1);
        }
    }

    return $violations;
}

it('не содержит литеральных цветов в боевых стилях компонентов', function (): void {
    $files = File::allFiles(resource_path('css/components'));
    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'css') {
            continue;
        }

        $violations = array_merge(
            $violations,
            uiStyleViolations(str_replace('\\', '/', $file->getPathname()), $file->getContents()),
        );
    }

    $violations = array_merge(
        $violations,
        uiStyleViolations('resources/css/app.css', File::get(resource_path('css/app.css'))),
    );

    expect($violations)->toBe([]);
});

it('не содержит литеральных цветов в боевых Blade-шаблонах', function (): void {
    $violations = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // preview — эталон стиля; welcome — стартовая страница Laravel.
        if (str_contains($path, '/views/preview/') || $file->getFilename() === 'welcome.blade.php') {
            continue;
        }

        $violations = array_merge($violations, uiStyleViolations($path, $file->getContents()));
    }

    expect($violations)->toBe([]);
});

it('объявляет роли токенов для обеих тем', function (): void {
    $tokens = File::get(resource_path('css/tokens.css'));

    expect($tokens)
        ->toContain(':root,')
        ->toContain("[data-theme='light']")
        ->toContain("[data-theme='dark']")
        ->toContain('--radius: 0.625rem');

    $roles = [
        '--token-canvas',
        '--token-surface',
        '--token-surface-raised',
        '--token-text',
        '--token-muted',
        '--token-border',
        '--token-accent',
        '--token-accent-strong',
        '--token-accent-soft',
        '--token-action-fill',
        '--token-action-fill-hover',
        '--token-action-fill-muted',
        '--token-action-text',
        '--token-action-ring',
        '--token-neutral-fill',
        '--token-neutral-fill-hover',
        '--token-success',
        '--token-warning',
        '--token-error',
        '--token-info',
        '--token-focus',
    ];

    foreach ($roles as $role) {
        expect(substr_count($tokens, $role.':'))->toBe(2);
    }
});

/**
 * Достаёт литеральные токены темы из resources/css/tokens.css.
 *
 * @return array<string, string>
 */
function uiThemeTokens(string $css, string $theme): array
{
    $pattern = $theme === 'dark'
        ? "/\\[data-theme='dark'\\]\\s*\\{(.*?)\\}/s"
        : "/:root,\\s*\\[data-theme='light'\\]\\s*\\{(.*?)\\}/s";

    if (preg_match($pattern, $css, $matches) !== 1) {
        return [];
    }

    preg_match_all('/--token-([a-z-]+):\\s*(#[0-9a-fA-F]{6});/', $matches[1], $found, PREG_SET_ORDER);

    $tokens = [];

    foreach ($found as $match) {
        $tokens['--token-'.$match[1]] = strtolower($match[2]);
    }

    return $tokens;
}

/**
 * Относительная яркость цвета по WCAG 2.x.
 */
function uiRelativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = [];

    foreach ([0, 2, 4] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.04045
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Коэффициент контраста двух цветов по WCAG 2.x.
 */
function uiContrast(string $foreground, string $background): float
{
    $first = uiRelativeLuminance($foreground);
    $second = uiRelativeLuminance($background);

    return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
}

it('обеспечивает контраст текста состояний и границ полей', function (): void {
    $css = File::get(resource_path('css/tokens.css'));

    $pairs = [
        ['--token-success', '--token-success-soft'],
        ['--token-warning', '--token-warning-soft'],
        ['--token-error', '--token-error-soft'],
        ['--token-info', '--token-info-soft'],
    ];

    foreach (['light', 'dark'] as $theme) {
        $tokens = uiThemeTokens($css, $theme);
        expect($tokens)->toHaveKeys([
            '--token-surface',
            '--token-border-control',
            '--token-success',
            '--token-success-soft',
            '--token-warning',
            '--token-warning-soft',
            '--token-error',
            '--token-error-soft',
            '--token-info',
            '--token-info-soft',
        ]);

        // FR-UI-156: текст на мягком фоне состояния — не ниже 4.5:1.
        foreach ($pairs as [$foreground, $background]) {
            expect(uiContrast($tokens[$foreground], $tokens[$background]))
                ->toBeGreaterThanOrEqual(4.5);
        }

        // FR-UI-156: граница поля к поверхности — не ниже 3:1.
        expect(uiContrast($tokens['--token-border-control'], $tokens['--token-surface']))
            ->toBeGreaterThanOrEqual(3.0);
    }
});
