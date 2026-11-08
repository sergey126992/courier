<?php

declare(strict_types=1);

/**
 * Документация этапа M10 не расходится с кодом и сама по себе целостна.
 *
 * Guard-тесты читают файлы репозитория: битая ссылка, отсутствующий документ
 * или запрос коллекции вне спецификации считаются дефектом.
 */

/**
 * Корень приложения.
 */
function docsRoot(): string
{
    return base_path();
}

/**
 * Все markdown-файлы документации приложения и корневой README.
 *
 * @return list<string>
 */
function docsMarkdownFiles(): array
{
    $files = [docsRoot().'/README.md'];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(docsRoot().'/docs'));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Приводит путь к виду без `.` и `..`.
 */
function docsNormalizePath(string $path): string
{
    $parts = [];

    foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($parts);

            continue;
        }

        $parts[] = $part;
    }

    return '/'.implode('/', $parts);
}

it('не содержит битых относительных ссылок в документации', function (): void {
    $broken = [];
    $root = docsNormalizePath(docsRoot());

    foreach (docsMarkdownFiles() as $file) {
        $content = (string) file_get_contents($file);

        preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $content, $matches);

        foreach ($matches[1] as $link) {
            // Ссылки вида <путь с пробелами> записываются в угловых скобках.
            $target = trim(trim($link), '<>');

            if ($target === '' || str_starts_with($target, 'http') || str_starts_with($target, '#')) {
                continue;
            }

            // Якорь внутри файла отбрасывается: проверяется сам файл.
            $target = str_replace('%20', ' ', explode('#', $target)[0]);

            if ($target === '') {
                continue;
            }

            $path = docsNormalizePath(
                str_starts_with($target, '/') ? $target : dirname($file).'/'.$target,
            );

            // Файлы требований лежат в корне репозитория, вне образа приложения:
            // такие ссылки проверяются при чтении на хосте, а не тестом в контейнере.
            if (! str_starts_with($path, $root.'/')) {
                continue;
            }

            if (! file_exists($path)) {
                $broken[] = basename($file).' → '.$link;
            }
        }
    }

    expect($broken)->toBe([]);
});

it('содержит ключевые документы и демонстрационный сценарий', function (): void {
    $expected = [
        'docs/13-Демо-сценарий.md',
        'docs/12-Демо-данные.md',
        'docs/api/README.md',
        'docs/adr/ADR-0012-документация-api.md',
        'docs/adr/ADR-0013-демо-данные.md',
    ];

    foreach ($expected as $relative) {
        $path = docsRoot().'/'.$relative;

        expect(file_exists($path))->toBeTrue("Нет файла {$relative}")
            ->and(strlen(trim((string) file_get_contents($path))))->toBeGreaterThan(300);
    }
});

it('описывает в спецификации каждый запрос коллекции', function (): void {
    $collection = (string) file_get_contents(docsRoot().'/docs/api/courier.http');

    /** @var array<string, mixed> $document */
    $document = json_decode(
        (string) file_get_contents(docsRoot().'/'.(string) config('scramble.export_path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $paths = array_keys((array) $document['paths']);

    $normalize = static fn (string $path): string => (string) preg_replace(
        ['/\{\{[^}]+\}\}/', '/\{[^}]+\}/'],
        ['{}', '{}'],
        $path,
    );

    $documented = array_map(
        static fn (string $path): string => $normalize($path),
        array_map(static fn (string $path): string => (string) $path, $paths),
    );

    preg_match_all('#^(GET|POST|PATCH|PUT|DELETE) \{\{baseUrl\}\}([^\s?]+)#m', $collection, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    $missing = [];

    foreach ($matches as [, $method, $path]) {
        $path = (string) preg_replace('#^/api#', '', $path);

        if (! in_array($normalize($path), $documented, true)) {
            $missing[] = $method.' '.$path;
        }
    }

    expect($missing)->toBe([]);
});

it('ссылается в README на документацию этапа', function (): void {
    $readme = (string) file_get_contents(docsRoot().'/README.md');

    foreach (['docs/api/', 'docs/12-Демо-данные.md', 'docs/13-Демо-сценарий.md', 'docs/Производительность/'] as $target) {
        expect($readme)->toContain($target);
    }
});
