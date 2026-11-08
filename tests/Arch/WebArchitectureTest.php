<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\SplFileInfo;

/*
|--------------------------------------------------------------------------
| Arch-тесты веб-интерфейса (ЧТЗ 9.4)
|--------------------------------------------------------------------------
|
| FR-UI-142, 144, 146, 147, 151, 194, 196, 197: экраны не ходят в базу,
| тексты живут в переводах, прокрутку задаёт только `scroll-area`, права
| проверяют политики, у каждого компонента есть карточка, а вход открывается
| GET-страницей по имени `login`.
|
| Проверки только читают файлы и не обращаются к базе: прогон быстрый.
| Литеральные цвета и палитровые утилиты проверяет UiThemeGuardTest.
|
*/

/**
 * Заменяет комментарии пробелами, сохраняя позиции и переводы строк.
 */
function webArchMaskComments(string $contents): string
{
    $patterns = [
        '/\{\{--.*?--\}\}/s', // комментарий Blade
        '#/\*.*?\*/#s',       // блочный комментарий PHP
        '#^[ \t]*//[^\n]*#m', // строчный комментарий на всю строку
    ];

    foreach ($patterns as $pattern) {
        $contents = (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]),
            $contents,
        );
    }

    return $contents;
}

/**
 * Возвращает нарушения в виде "путь:строка (фрагмент)".
 *
 * @param list<string> $needles
 * @return list<string>
 */
function webArchSnippetViolations(string $path, string $contents, array $needles): array
{
    $violations = [];

    foreach ($needles as $needle) {
        if (! str_contains($contents, $needle)) {
            continue;
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (str_contains($line, $needle)) {
                $violations[] = sprintf('%s:%d (%s)', $path, $index + 1, $needle);
            }
        }
    }

    return $violations;
}

/**
 * Находит кириллические строковые литералы вне __(), trans() и @lang().
 *
 * @return list<string>
 */
function webArchCyrillicViolations(string $path, string $contents): array
{
    $contents = webArchMaskComments($contents);

    preg_match_all(
        "/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/",
        $contents,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );

    $violations = [];

    foreach ($matches as $match) {
        /** @var array{0: string, 1: int} $whole */
        $whole = $match[0];
        // Первая группа — литерал в одинарных кавычках, вторая — в двойных.
        $literal = ($match[1][0] ?? '').($match[2][0] ?? '');

        if (preg_match('/[А-Яа-яЁё]/u', $literal) !== 1) {
            continue;
        }

        $position = (int) $whole[1];
        $before = substr($contents, max(0, $position - 40), min(40, $position));

        // Текст интерфейса берётся из переводов, а не пишется в шаблоне (FR-UI-197).
        if (preg_match('/(?:__|trans|trans_choice|@lang)\(\s*$/', $before) === 1) {
            continue;
        }

        $violations[] = sprintf('%s:%d', $path, substr_count(substr($contents, 0, $position), "\n") + 1);
    }

    return $violations;
}

/**
 * Файлы Blade-шаблонов приложения.
 *
 * @return list<SplFileInfo>
 */
function webArchBladeFiles(): array
{
    $files = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * Признак эталонного контура предпросмотра: там литералы допустимы.
 */
function webArchIsPreview(string $path): bool
{
    return str_contains(str_replace('\\', '/', $path), '/views/preview/');
}

it('не обращается к базе и контейнеру из Blade-шаблонов', function (): void {
    $files = webArchBladeFiles();
    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        $violations = array_merge($violations, webArchSnippetViolations(
            str_replace('\\', '/', $file->getRelativePathname()),
            $file->getContents(),
            ['DB::', 'Model::query(', '->where(', 'app()->make'],
        ));
    }

    expect($violations)->toBe([]);
});

it('не работает с базой напрямую в компонентах Livewire', function (): void {
    $files = File::allFiles(app_path('Livewire'));
    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        $violations = array_merge($violations, webArchSnippetViolations(
            str_replace('\\', '/', $file->getRelativePathname()),
            $file->getContents(),
            ['DB::', 'Illuminate\Support\Facades\DB'],
        ));
    }

    expect($violations)->toBe([]);
});

it('не содержит кириллических литералов вне перевода в Blade-шаблонах', function (): void {
    $files = webArchBladeFiles();
    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        if (webArchIsPreview($file->getPathname())) {
            continue;
        }

        $violations = array_merge(
            $violations,
            webArchCyrillicViolations(str_replace('\\', '/', $file->getRelativePathname()), $file->getContents()),
        );
    }

    expect($violations)->toBe([]);
});

it('не содержит кириллических литералов вне перевода в компонентах Livewire', function (): void {
    $files = File::allFiles(app_path('Livewire'));
    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        $violations = array_merge(
            $violations,
            webArchCyrillicViolations(str_replace('\\', '/', $file->getRelativePathname()), $file->getContents()),
        );
    }

    expect($violations)->toBe([]);
});

it('не задаёт прокрутку в Blade вне компонента scroll-area', function (): void {
    $files = webArchBladeFiles();
    expect($files)->not->toBeEmpty();

    $pattern = '/\boverflow-(?:x-|y-)?(?:auto|scroll)\b/';
    $violations = [];

    foreach ($files as $file) {
        // FR-UI-144: единственная прокручиваемая область — `scroll-area`.
        if ($file->getFilename() === 'scroll-area.blade.php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getRelativePathname());

        foreach (preg_split('/\R/', webArchMaskComments($file->getContents())) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $violations[] = sprintf('%s:%d', $path, $index + 1);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('не сравнивает роль по месту в веб-контроллерах и Livewire', function (): void {
    $files = array_merge(
        File::allFiles(app_path('Http/Controllers/Web')),
        File::allFiles(app_path('Livewire')),
    );

    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        $contents = webArchMaskComments($file->getContents());

        // Права ролей проверяют политики, а не код экрана (раздел 6 ЧТЗ).
        if (str_contains($contents, 'Role::')
            || preg_match('/->role\s*(?:===|!==|==|!=)/', $contents) === 1
            || preg_match('/\brole\b\s*(?:===|!==|==|!=)\s*[\'"]/', $contents) === 1) {
            $violations[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    expect($violations)->toBe([]);
});

it('имеет карточку компонента для каждого файла components/app', function (): void {
    $components = [];

    foreach (File::files(resource_path('views/components/app')) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $components[] = (string) preg_replace('/\.blade\.php$/', '', $file->getFilename());
        }
    }

    expect($components)->not->toBeEmpty();

    // Карточки живут в индексе компонентов репозитория, а не в приложении.
    $cards = null;

    foreach ([base_path('.github/components/ui'), dirname(base_path()).'/.github/components/ui'] as $candidate) {
        if (File::isDirectory($candidate)) {
            $cards = $candidate;
            break;
        }
    }

    if ($cards === null) {
        $this->markTestSkipped('Каталог карточек .github/components/ui не найден в этом окружении.');
    }

    $missing = [];

    foreach ($components as $component) {
        if (! File::exists($cards.'/'.$component.'.md')) {
            $missing[] = $component.'.blade.php';
        }
    }

    expect($missing)->toBe([]);
});

it('регистрирует маршрут login как GET-страницу входа', function (): void {
    expect(Route::has('login'))->toBeTrue();

    $route = Route::getRoutes()->getByName('login');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toContain('GET');

    $this->get('/login')
        ->assertOk()
        ->assertSee(__('ui.forms.login_title'))
        ->assertSee(__('ui.forms.submit_login'));
});
