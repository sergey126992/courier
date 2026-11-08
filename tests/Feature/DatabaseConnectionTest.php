<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('подключён к PostgreSQL', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('применяет миграции на тестовой базе', function (): void {
    expect(DB::connection()->getDatabaseName())->toStartWith('courier_testing')
        ->and(Schema::hasTable('users'))->toBeTrue();
});
