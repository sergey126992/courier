<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Настройки интерфейса (FR-UI-196)
|--------------------------------------------------------------------------
|
| Ключи и значения по умолчанию сверяются с ЧТЗ «Кабинет и панель
| диспетчера». Проверка быстрая: сеть, письма и база не нужны.
|
*/

it('содержит все ключи FR-UI-196 и только их', function (): void {
    expect(array_keys(config('ui')))->toBe([
        'name',
        'brand',
        'locale',
        'theme',
        'roles',
        'pagination',
        'dispatcher',
        'tracking',
        'links',
    ]);
});

it('задаёт безопасные значения по умолчанию', function (): void {
    expect(config('ui.name'))->toBe('Курьерская служба')
        ->and(config('ui.brand'))->toBe('Курьер')
        ->and(config('ui.locale'))->toBe('ru')
        ->and(config('ui.theme'))->toBe([
            'default' => 'system',
            'cookie' => 'ui_theme',
        ])
        ->and(config('ui.pagination'))->toBe([
            'per_page' => 20,
            'max' => 100,
        ])
        ->and(config('ui.dispatcher'))->toBe([
            'refresh_seconds' => 20,
        ])
        ->and(config('ui.tracking'))->toBe([
            'public_route' => 'tracking.public.web',
            'refresh_seconds' => 30,
        ])
        ->and(config('ui.links'))->toBe([
            'email_verification_route' => 'verification.verify.web',
            'password_reset_route' => 'password.reset',
        ]);
});

it('покрывает домашними страницами все роли', function (): void {
    $expected = [
        'sender' => 'cabinet.orders',
        'courier' => 'account',
        'dispatcher' => 'dispatcher.queue',
        'admin' => 'account',
    ];

    $roleValues = array_map(static fn (Role $role): string => $role->value, Role::cases());

    expect(array_keys(config('ui.roles')))->toBe(array_keys($expected))
        ->and(array_keys(config('ui.roles')))->toBe($roleValues);

    foreach ($expected as $role => $home) {
        expect(config('ui.roles.'.$role.'.home'))->toBe($home);
    }
});

it('держит тему и пагинацию в допустимых пределах', function (): void {
    $theme = config('ui.theme.default');
    $perPage = config('ui.pagination.per_page');
    $max = config('ui.pagination.max');
    $refresh = config('ui.dispatcher.refresh_seconds');
    $trackingRefresh = config('ui.tracking.refresh_seconds');

    expect($theme)->toBeIn(['light', 'dark', 'system'])
        ->and($perPage)->toBeInt()->toBeGreaterThan(0)
        ->and($max)->toBeInt()->toBeGreaterThanOrEqual($perPage)
        ->and($refresh)->toBeInt()->toBeGreaterThan(0)
        // Интервал трекинга не нулевой: поллинг с нулём отключил бы обновление (FR-UI-128).
        ->and($trackingRefresh)->toBeInt()->toBeGreaterThan(0)
        ->and($trackingRefresh)->toBeGreaterThanOrEqual(5);
});

it('читает каждое значение через env()', function (): void {
    $source = File::get(config_path('ui.php'));

    $variables = [
        'UI_NAME',
        'UI_BRAND',
        'UI_LOCALE',
        'UI_THEME_DEFAULT',
        'UI_THEME_COOKIE',
        'UI_HOME_SENDER',
        'UI_HOME_COURIER',
        'UI_HOME_DISPATCHER',
        'UI_HOME_ADMIN',
        'UI_PAGINATION_PER_PAGE',
        'UI_PAGINATION_MAX',
        'UI_DISPATCHER_REFRESH_SECONDS',
        'UI_TRACKING_PUBLIC_ROUTE',
        'UI_TRACKING_REFRESH_SECONDS',
        'UI_EMAIL_VERIFICATION_ROUTE',
        'UI_PASSWORD_RESET_ROUTE',
    ];

    foreach ($variables as $variable) {
        expect($source)->toContain("env('".$variable."'");
    }
});
