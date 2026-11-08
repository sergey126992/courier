<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

arch('домен не зависит от фреймворка')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate',
        'Laravel',
        'Eloquent',
        'Http',
        'DB',
    ]);

arch('в коде нет отладочного вывода')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();

arch('контроллеры не работают с базой напрямую')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Query\Builder',
    ]);

it('не содержит прямых запросов к базе в контроллерах', function (): void {
    $controllers = File::allFiles(app_path('Http/Controllers'));

    expect($controllers)->not->toBeEmpty();

    foreach ($controllers as $controller) {
        $contents = $controller->getContents();

        expect($contents)->not->toContain('DB::')
            ->and($contents)->not->toContain('Model::query(');
    }
});

it('не содержит проверок роли по месту в контроллерах', function (): void {
    $controllers = File::allFiles(app_path('Http/Controllers'));

    expect($controllers)->not->toBeEmpty();

    foreach ($controllers as $controller) {
        $contents = $controller->getContents();

        // Права проверяют политики и разрешения токена, а не код контроллера.
        expect($contents)->not->toContain('->role')
            ->and($contents)->not->toContain('Role::')
            ->and($contents)->not->toContain('hasVerifiedEmail(');
    }
});

it('не обращается к фреймворку, базе и конфигурации в домене заказа', function (): void {
    $files = File::allFiles(app_path('Domain/Order'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Статусы и переходы — чистый домен: права ролей и запись истории снаружи.
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('не обращается к фреймворку, базе и конфигурации в домене географии', function (): void {
    $files = File::allFiles(app_path('Domain/Geography'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Зоны и координаты приходят в домен объектами и портами, а не из фреймворка.
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('не обращается к фреймворку и конфигурации в домене расчёта', function (): void {
    $files = File::allFiles(app_path('Domain/Pricing'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Параметры расчёта приходят в домен объектом, а не читаются из конфигурации.
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('не обращается к фреймворку, базе и конфигурации в домене событий', function (): void {
    $files = File::allFiles(app_path('Domain/Events'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Реестр и конверт — чистый домен: строка Outbox приходит в них значениями,
        // а Eloquent, конфигурация и база остаются в прикладном слое.
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('не обращается к фреймворку, базе и конфигурации в домене трекинга', function (): void {
    $files = File::allFiles(app_path('Domain/Tracking'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Остаток пути считается по переданным аргументам: ни HTTP, ни базы,
        // ни конфигурации домен трекинга не знает.
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('не обращается к фреймворку, базе и конфигурации в домене уведомлений', function (): void {
    $files = File::allFiles(app_path('Domain/Notifications'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Матрица, правила, состояния и шаблоны — чистый домен: конфигурация,
        // Eloquent и база остаются в прикладном слое, а политика приходит
        // готовым объектом-значением (FR-NTF-142, FR-NTF-143).
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('не обращается к фреймворку, базе и конфигурации в домене отчётов', function (): void {
    $files = File::allFiles(app_path('Domain/Reporting'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        // Период, метрики и разрез — чистый домен: валюта, предел периода
        // и правила округления приходят значениями, а не из конфигурации.
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::');
    }
});

it('вызывает env() только в конфигурации', function (): void {
    // FR-UI-196: шаблоны и классы читают значения через config(), а env()
    // остаётся единственной точкой чтения окружения — config/*.php.
    $paths = [
        app_path(),
        base_path('routes'),
        resource_path('views'),
        database_path(),
        lang_path(),
        base_path('bootstrap'),
    ];

    foreach ($paths as $path) {
        if (! File::isDirectory($path)) {
            continue;
        }

        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            expect($file->getContents())->not->toContain('env(');
        }
    }
});

arch('домен и действия не зависят от драйвера транспорта событий')
    ->expect(['App\Domain', 'App\Actions'])
    ->not->toUse([
        'Predis',
        'Illuminate\Redis',
        'Illuminate\Support\Facades\Redis',
        'App\Services\Events\LocalEventBus',
        'App\Services\Events\RedisStreamEventBus',
    ]);

it('читает конфигурацию транспорта только в сервис-провайдере', function (): void {
    $files = File::allFiles(app_path('Services/Events'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = $file->getContents();

        expect($contents)
            ->not->toContain('config(')
            ->and($contents)->not->toContain('env(');
    }
});
