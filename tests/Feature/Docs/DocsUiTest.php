<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

/**
 * Страница документации API: только локальная среда и тесты.
 *
 * Схема раскрывает все эндпоинты, поэтому вне local/testing маршрут
 * не регистрируется, а гейт доступа не пропускает никого (FR-DOC-123, FR-DOC-201).
 */
it('открывает страницу описания API в локальной среде без токена', function (): void {
    expect(app()->environment())->toBe('testing');

    $this->get('/docs/api')->assertOk();
});

it('не пропускает к описанию вне локальной среды и тестов', function (): void {
    $original = app()->environment();

    try {
        app()->detectEnvironment(static fn (): string => 'production');

        expect(Gate::allows('viewApiDocs'))->toBeFalse();
    } finally {
        app()->detectEnvironment(static fn (): string => $original);
    }

    expect(Gate::allows('viewApiDocs'))->toBeTrue();
});
