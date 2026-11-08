<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| GET /health при недоступной базе (FR-NTF-214, ЧТЗ 9.2 п. 7)
|--------------------------------------------------------------------------
|
| Запрос к базе подменяется отказом: сервис обязан ответить 503 и
| degraded, а не упасть. RefreshDatabase здесь не используется, иначе
| подмена подключения мешает откату транзакции теста.
|
*/

it('отвечает 503 и degraded, когда база недоступна', function (): void {
    bindFakeRedis(['xinfo' => [[
        'name' => 'notifications-service-test',
        'consumers' => 1,
        'pending' => 0,
        'last-delivered-id' => '0-0',
        'lag' => 0,
    ]]]);

    DB::shouldReceive('connection')->andThrow(new RuntimeException('database is down'));

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database', 'error')
        ->assertJsonPath('checks.redis', 'ok');
});
