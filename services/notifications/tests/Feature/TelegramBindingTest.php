<?php

declare(strict_types=1);

use App\Services\Notifications\Telegram\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Привязка Telegram: токены и API (ЧТЗ 8.1, 8.3, 8.4; FR-NTF-411…421)
|--------------------------------------------------------------------------
|
| Проверяются выпуск токена, одноразовое подтверждение, истечение срока,
| отвязка, замена привязки, производное состояние и отсутствие персональных
| данных в ответах. Время фиксируется `Carbon::setTestNow`, сети нет.
|
*/

uses(RefreshDatabase::class);

const TELEGRAM_API_TOKEN = 'test-service-token';
const TELEGRAM_TEST_BOT = 'courier_test_bot';
const TELEGRAM_TEST_CHAT = '555000111';
const TELEGRAM_TEST_CHAT_OTHER = '555000222';
const TELEGRAM_TEST_USER = 10;

/**
 * @return array<string, string>
 */
function telegramHeaders(): array
{
    return ['Authorization' => 'Bearer '.TELEGRAM_API_TOKEN];
}

/**
 * Значение токена из deep-link: открытое значение возвращается один раз.
 */
function telegramTokenFromLink(string $deepLink): string
{
    $query = explode('?', $deepLink, 2)[1] ?? '';
    parse_str($query, $params);
    $token = $params['start'] ?? null;

    return is_string($token) ? $token : '';
}

/**
 * Выпускает токен и возвращает его открытое значение.
 */
function telegramStart(int $userId = TELEGRAM_TEST_USER): string
{
    return telegramTokenFromLink(app(TelegramLinkService::class)->start($userId)->deepLink);
}

beforeEach(function (): void {
    config([
        'notifications.api.token' => TELEGRAM_API_TOKEN,
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_username' => TELEGRAM_TEST_BOT,
        'notifications.telegram.link_token_ttl_seconds' => 900,
        'notifications.telegram.updates_mode' => 'polling',
        'notifications.telegram.webhook_secret' => '',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('выпускает одноразовый токен, deep-link и статус ожидания', function (): void {
    $response = $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/start', ['user_id' => TELEGRAM_TEST_USER])
        ->assertStatus(201)
        ->assertJson([
            'status' => 'pending',
            'expires_at' => '2026-11-07T10:15:00Z',
        ]);

    $deepLink = (string) $response->json('deep_link');
    $token = telegramTokenFromLink($deepLink);

    expect($token)->not->toBe('')
        ->and($deepLink)->toStartWith('https://t.me/'.TELEGRAM_TEST_BOT.'?start=');

    // Открытого значения в базе нет: хранится только SHA-256 (FR-NTF-413).
    expect(DB::table('telegram_link_tokens')->where('user_id', TELEGRAM_TEST_USER)->count())->toBe(1)
        ->and(DB::table('telegram_link_tokens')->value('token_hash'))->toBe(hash('sha256', $token))
        ->and(DB::table('telegram_link_tokens')->where('token_hash', $token)->count())->toBe(0);
});

it('подтверждает привязку и отдаёт статус без адреса', function (): void {
    $token = telegramStart();

    $response = $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $token,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(200)
        ->assertJson([
            'channel' => 'telegram',
            'status' => 'verified',
            'verified_at' => '2026-11-07T10:00:00Z',
            'revoked_at' => null,
        ]);

    $body = (string) $response->getContent();

    expect($body)->not->toContain(TELEGRAM_TEST_CHAT)
        ->not->toContain($token)
        ->not->toContain('address');

    $this->assertDatabaseHas('channel_bindings', [
        'user_id' => TELEGRAM_TEST_USER,
        'channel' => 'telegram',
        'revoked_at' => null,
    ]);

    expect(DB::table('channel_bindings')->value('address'))->toBe(TELEGRAM_TEST_CHAT)
        ->and(DB::table('telegram_link_tokens')->whereNotNull('used_at')->count())->toBe(1);
});

it('отклоняет повторное подтверждение того же токена', function (): void {
    $token = telegramStart();

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $token,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(200);

    $verifiedAt = (string) DB::table('channel_bindings')->value('verified_at');

    $response = $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $token,
            'chat_id' => TELEGRAM_TEST_CHAT_OTHER,
        ])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'status' => 409,
            'code' => 'TELEGRAM_LINK_TOKEN_INVALID',
        ]);

    expect((string) $response->getContent())->not->toContain($token);

    // Привязка не меняется: адрес остался прежним, активная строка одна.
    expect(DB::table('channel_bindings')->value('address'))->toBe(TELEGRAM_TEST_CHAT)
        ->and((string) DB::table('channel_bindings')->value('verified_at'))->toBe($verifiedAt)
        ->and(DB::table('channel_bindings')->whereNull('revoked_at')->count())->toBe(1);
});

it('отвечает 410 на истёкший токен', function (): void {
    $token = telegramStart();

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:16:00', 'UTC'));

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $token,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(410)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'status' => 410,
            'code' => 'TELEGRAM_LINK_TOKEN_EXPIRED',
        ]);

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('аннулирует предыдущий действующий токен новым выпуском', function (): void {
    $first = telegramStart();
    $second = telegramStart();

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $first,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(409)
        ->assertJson(['code' => 'TELEGRAM_LINK_TOKEN_INVALID']);

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $second,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(200)
        ->assertJson(['status' => 'verified']);

    expect(DB::table('channel_bindings')->whereNull('revoked_at')->count())->toBe(1);
});

it('отвязывает канал и сохраняет строку в истории', function (): void {
    $token = telegramStart();

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $token,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(200);

    $this->withHeaders(telegramHeaders())
        ->deleteJson('/api/v1/channel-bindings/telegram', ['user_id' => TELEGRAM_TEST_USER])
        ->assertStatus(204);

    $this->assertDatabaseHas('channel_bindings', [
        'user_id' => TELEGRAM_TEST_USER,
        'channel' => 'telegram',
    ]);

    expect(DB::table('channel_bindings')->whereNotNull('revoked_at')->count())->toBe(1);

    $this->withHeaders(telegramHeaders())
        ->deleteJson('/api/v1/channel-bindings/telegram', ['user_id' => TELEGRAM_TEST_USER])
        ->assertStatus(409)
        ->assertJson([
            'status' => 409,
            'code' => 'TELEGRAM_BINDING_ABSENT',
        ]);
});

it('заменяет привязку при смене адреса и держит одну активную строку', function (): void {
    $first = telegramStart();

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $first,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(200);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:05:00', 'UTC'));

    $second = telegramStart();

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $second,
            'chat_id' => TELEGRAM_TEST_CHAT_OTHER,
        ])
        ->assertStatus(200);

    expect(DB::table('channel_bindings')->count())->toBe(2)
        ->and(DB::table('channel_bindings')->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('channel_bindings')->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('channel_bindings')->whereNull('revoked_at')->value('address'))->toBe(TELEGRAM_TEST_CHAT_OTHER);
});

it('показывает ожидание подтверждения и статус привязки без адресов', function (): void {
    telegramStart();

    $pending = $this->withHeaders(telegramHeaders())
        ->getJson('/api/v1/channel-bindings?user_id='.TELEGRAM_TEST_USER)
        ->assertStatus(200)
        ->assertJsonPath('bindings.0.channel', 'telegram')
        ->assertJsonPath('bindings.0.status', 'pending');

    expect((string) $pending->getContent())->not->toContain(TELEGRAM_TEST_CHAT);

    $token = telegramStart();

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', [
            'token' => $token,
            'chat_id' => TELEGRAM_TEST_CHAT,
        ])
        ->assertStatus(200);

    $verified = $this->withHeaders(telegramHeaders())
        ->getJson('/api/v1/channel-bindings?user_id='.TELEGRAM_TEST_USER)
        ->assertStatus(200)
        ->assertJsonPath('bindings.0.status', 'verified');

    expect((string) $verified->getContent())->not->toContain(TELEGRAM_TEST_CHAT);
});

it('отказывает в выпуске токена, когда канал выключен', function (): void {
    config(['notifications.enabled_channels' => ['email', 'push']]);

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/start', ['user_id' => TELEGRAM_TEST_USER])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'status' => 422,
            'code' => 'TELEGRAM_CHANNEL_DISABLED',
        ]);

    $this->assertDatabaseMissing('telegram_link_tokens', ['user_id' => TELEGRAM_TEST_USER]);
});

it('отвечает 422 на некорректные поля в формате problem+json', function (): void {
    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/start', ['user_id' => 0])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'VALIDATION_FAILED']);

    $this->withHeaders(telegramHeaders())
        ->postJson('/api/v1/channel-bindings/telegram/confirm', ['token' => 'abc'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->withHeaders(telegramHeaders())
        ->getJson('/api/v1/channel-bindings')
        ->assertStatus(422)
        ->assertJson(['code' => 'VALIDATION_FAILED']);
});

it('требует сервисный токен на маршрутах привязки', function (): void {
    $this->postJson('/api/v1/channel-bindings/telegram/start', ['user_id' => TELEGRAM_TEST_USER])
        ->assertStatus(401)
        ->assertJson(['code' => 'INVALID_SERVICE_TOKEN']);
});
