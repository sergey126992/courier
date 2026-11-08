<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| /health не обращается к Telegram (FR-OPS-414, FR-OPS-416)
|--------------------------------------------------------------------------
|
| Внешний канал не входит в критичный путь здоровья сервиса: проверка
| читает базу, Redis и схемы, а Bot API не вызывается. Контракт ответа
| (`status`, `checks`, `lag_seconds`, `pending`, `generated_at`) не меняется.
|
*/

it('не обращается к Telegram при проверке здоровья', function (): void {
    Http::fake();

    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'secret-bot-token',
        'notifications.telegram.bot_username' => 'courier_bot',
    ]);

    bindFakeRedis([
        'xinfo' => [[
            'name' => (string) config('notifications.transport.consumer_group'),
            'consumers' => 1,
            'pending' => 0,
            'last-delivered-id' => '0-0',
            'entries-read' => null,
            'lag' => 0,
        ]],
    ]);

    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks', ['database' => 'ok', 'redis' => 'ok', 'contracts' => 'ok'])
        ->assertJsonPath('lag_seconds', 0)
        ->assertJsonPath('pending', 0);

    // Ни одного запроса к Bot API: недоступность Telegram не переводит
    // сервис в degraded (FR-OPS-414).
    Http::assertNothingSent();

    $content = (string) $response->getContent();

    expect($content)->not->toContain('secret-bot-token')
        ->not->toContain('telegram');
});
