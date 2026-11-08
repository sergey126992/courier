<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Telegram\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Вебхук бота Telegram (ЧТЗ 9.2 п. 11, FR-NTF-442, ЧТЗ 8.4)
|--------------------------------------------------------------------------
|
| Маршрут существует только в режиме `webhook`, доступ закрыт секретом,
| неверный секрет даёт 403 problem+json. Повтор апдейта с тем же `update_id`
| второго эффекта не даёт: привязка остаётся одна.
|
*/

uses(RefreshDatabase::class);

const WEBHOOK_TEST_SECRET = 'test-webhook-secret';
const WEBHOOK_TEST_CHAT = 900100200;
const WEBHOOK_TEST_USER = 41;

/**
 * Апдейт с командой `/start <token>`.
 *
 * @return array<string, mixed>
 */
function telegramWebhookUpdate(int $updateId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'chat' => ['id' => WEBHOOK_TEST_CHAT, 'type' => 'private'],
            'text' => $text,
        ],
    ];
}

/**
 * Открытое значение выпущенного токена привязки.
 */
function telegramWebhookToken(int $userId = WEBHOOK_TEST_USER): string
{
    $start = app(TelegramLinkService::class)->start($userId);
    $query = explode('?', $start->deepLink, 2)[1] ?? '';
    parse_str($query, $params);
    $token = $params['start'] ?? null;

    return is_string($token) ? $token : '';
}

/**
 * @return array<string, string>
 */
function telegramWebhookHeaders(string $secret = WEBHOOK_TEST_SECRET): array
{
    return ['X-Telegram-Bot-Api-Secret-Token' => $secret];
}

beforeEach(function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_username' => 'courier_test_bot',
        'notifications.telegram.link_token_ttl_seconds' => 900,
        'notifications.telegram.updates_mode' => 'webhook',
        'notifications.telegram.webhook_secret' => WEBHOOK_TEST_SECRET,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    app()->instance(TelegramBotClient::class, new FakeTelegramBotClient);

    // Маршрут вебхука регистрируется только в режиме `webhook`: при
    // загрузке приложения конфигурация была ещё `polling`, поэтому
    // маршруты перечитываются после смены режима.
    Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api.php'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('обрабатывает апдейт с валидным секретом и создаёт привязку', function (): void {
    $token = telegramWebhookToken();

    $response = $this->withHeaders(telegramWebhookHeaders())
        ->postJson('/api/v1/telegram/webhook', telegramWebhookUpdate(500, '/start '.$token))
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    $body = (string) $response->getContent();

    expect($body)->not->toContain((string) WEBHOOK_TEST_CHAT)
        ->not->toContain($token);

    $this->assertDatabaseHas('channel_bindings', [
        'user_id' => WEBHOOK_TEST_USER,
        'channel' => 'telegram',
        'revoked_at' => null,
    ]);
});

it('отклоняет апдейт с неверным секретом', function (): void {
    $token = telegramWebhookToken();

    $this->withHeaders(telegramWebhookHeaders('wrong-secret'))
        ->postJson('/api/v1/telegram/webhook', telegramWebhookUpdate(501, '/start '.$token))
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'status' => 403,
            'code' => 'TELEGRAM_WEBHOOK_SECRET_INVALID',
        ]);

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('отклоняет апдейт без секрета', function (): void {
    $this->postJson('/api/v1/telegram/webhook', telegramWebhookUpdate(502, '/start'))
        ->assertStatus(403)
        ->assertJson(['code' => 'TELEGRAM_WEBHOOK_SECRET_INVALID']);
});

it('не даёт второго эффекта при повторе update_id', function (): void {
    $token = telegramWebhookToken();
    $update = telegramWebhookUpdate(503, '/start '.$token);

    $this->withHeaders(telegramWebhookHeaders())
        ->postJson('/api/v1/telegram/webhook', $update)
        ->assertStatus(200);

    $verifiedAt = (string) DB::table('channel_bindings')->value('verified_at');

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:05:00', 'UTC'));

    $this->withHeaders(telegramWebhookHeaders())
        ->postJson('/api/v1/telegram/webhook', $update)
        ->assertStatus(200);

    expect(DB::table('channel_bindings')->count())->toBe(1)
        ->and((string) DB::table('channel_bindings')->value('verified_at'))->toBe($verifiedAt)
        ->and(DB::table('channel_bindings')->whereNull('revoked_at')->count())->toBe(1);
});

it('отвечает 200 на апдейт без команды привязки', function (): void {
    $this->withHeaders(telegramWebhookHeaders())
        ->postJson('/api/v1/telegram/webhook', telegramWebhookUpdate(504, 'привет'))
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});
