<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Вебхук Telegram не существует в режиме long polling (FR-NTF-442)
|--------------------------------------------------------------------------
|
| Маршрут регистрируется только при `telegram.updates_mode = webhook`:
| в режиме опроса апдейт нельзя прислать снаружи.
|
*/

uses(RefreshDatabase::class);

it('не регистрирует маршрут вебхука в режиме опроса', function (): void {
    config(['notifications.telegram.updates_mode' => 'polling']);

    $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1])
        ->assertStatus(404)
        ->assertJson(['code' => 'NOT_FOUND']);
});
