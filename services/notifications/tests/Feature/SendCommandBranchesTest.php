<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Ветки команды отправки (ЧТЗ 8.6, 9.2)
|--------------------------------------------------------------------------
|
| Проверяются ключи --limit и --id: некорректные значения дают код 2 и
| понятное сообщение. Долгоживущий цикл и обработчики сигналов требуют
| отдельного процесса и здесь не запускаются.
|
*/

uses(RefreshDatabase::class);

it('возвращает 2 на некорректный --limit и --id', function (): void {
    $this->artisan('notifications:send', ['--limit' => 'abc'])
        ->expectsOutputToContain('--limit')
        ->assertExitCode(2);

    $this->artisan('notifications:send', ['--id' => 'abc'])
        ->expectsOutputToContain('--id')
        ->assertExitCode(2);
});
