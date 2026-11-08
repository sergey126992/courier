<?php

declare(strict_types=1);

use App\Models\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| API device-токенов (ЧТЗ 8.1, FR-NTF-525…529)
|--------------------------------------------------------------------------
|
| Регистрация идемпотентна по токену, отзыв идемпотентен по идентификатору,
| токен не возвращается ни в одном ответе. Сети нет: база подменена
| транзакцией теста.
|
*/

uses(RefreshDatabase::class);

const DEVICE_API_TOKEN = 'test-service-token';

/**
 * @return array<string, string>
 */
function deviceApiHeaders(): array
{
    return ['Authorization' => 'Bearer '.DEVICE_API_TOKEN];
}

/**
 * Валидный по формату FCM-токен.
 */
function deviceApiToken(string $suffix = 'a'): string
{
    return 'fcm-token-'.str_repeat($suffix, 40);
}

beforeEach(function (): void {
    config(['notifications.api.token' => DEVICE_API_TOKEN]);
});

it('регистрирует новый токен и не возвращает его в ответе', function (): void {
    $token = deviceApiToken();

    $response = $this->postJson('/api/v1/device-tokens', [
        'user_id' => 7,
        'token' => $token,
        'platform' => 'android',
        'locale' => 'ru',
        'app_version' => '1.2.3',
    ], deviceApiHeaders());

    $response->assertStatus(201)
        ->assertJsonPath('data.user_id', 7)
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.fingerprint', DeviceToken::fingerprint($token))
        ->assertJsonMissingPath('data.token');

    expect((string) $response->getContent())->not->toContain($token);

    $this->assertDatabaseHas('device_tokens', [
        'user_id' => 7,
        'platform' => 'android',
        'fingerprint' => DeviceToken::fingerprint($token),
    ]);
});

it('обновляет строку при повторной регистрации того же токена', function (): void {
    $token = deviceApiToken();

    $this->postJson('/api/v1/device-tokens', [
        'user_id' => 7,
        'token' => $token,
        'platform' => 'android',
    ], deviceApiHeaders())->assertStatus(201);

    $this->postJson('/api/v1/device-tokens', [
        'user_id' => 9,
        'token' => $token,
        'platform' => 'ios',
    ], deviceApiHeaders())->assertStatus(200);

    expect(DeviceToken::query()->count())->toBe(1);

    $row = DeviceToken::query()->sole();

    expect($row->user_id)->toBe(9)
        ->and($row->platform)->toBe('ios')
        ->and($row->revoked_at)->toBeNull();
});

it('делает повторно зарегистрированный отозванный токен активным', function (): void {
    $token = deviceApiToken('b');
    DeviceToken::factory()->revoked()->create([
        'token' => $token,
        'fingerprint' => DeviceToken::fingerprint($token),
    ]);

    $this->postJson('/api/v1/device-tokens', [
        'user_id' => 7,
        'token' => $token,
        'platform' => 'android',
    ], deviceApiHeaders())->assertStatus(200);

    expect(DeviceToken::query()->where('token', $token)->sole()->revoked_at)->toBeNull();
});

it('отклоняет некорректные поля с кодом DEVICE_TOKEN_INVALID', function (): void {
    $response = $this->postJson('/api/v1/device-tokens', [
        'user_id' => 7,
        'token' => 'short',
        'platform' => 'android',
    ], deviceApiHeaders());

    $response->assertStatus(422)->assertJsonPath('code', 'DEVICE_TOKEN_INVALID');

    expect((string) $response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('отзывает токен идемпотентно', function (): void {
    $device = DeviceToken::factory()->create();

    $this->deleteJson('/api/v1/device-tokens/'.$device->id, [], deviceApiHeaders())
        ->assertStatus(204);

    expect($device->refresh()->revoked_at)->not->toBeNull();

    $this->deleteJson('/api/v1/device-tokens/'.$device->id, [], deviceApiHeaders())
        ->assertStatus(202);
});

it('возвращает 404 для неизвестного токена', function (): void {
    $this->deleteJson('/api/v1/device-tokens/999999', [], deviceApiHeaders())
        ->assertStatus(404)
        ->assertJsonPath('code', 'DEVICE_TOKEN_UNKNOWN');
});

it('требует сервисный токен', function (): void {
    $this->postJson('/api/v1/device-tokens', [
        'user_id' => 7,
        'token' => deviceApiToken(),
        'platform' => 'android',
    ])->assertStatus(401);
});

it('держит уникальность токена и индексы таблицы', function (): void {
    expect(Schema::hasTable('device_tokens'))->toBeTrue();

    $token = deviceApiToken('c');

    DeviceToken::factory()->create([
        'token' => $token,
        'fingerprint' => DeviceToken::fingerprint($token),
    ]);

    expectDatabaseRejection(fn (): DeviceToken => DeviceToken::factory()->create([
        'token' => $token,
        'fingerprint' => DeviceToken::fingerprint($token),
    ]));

    $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'device_tokens'"));
    $names = $indexes->pluck('indexname')->all();

    expect($names)->toContain('device_tokens_token_unique')
        ->and($names)->toContain('device_tokens_platform_index')
        ->and($names)->toContain('device_tokens_user_id_active_index');

    $partial = $indexes->firstWhere('indexname', 'device_tokens_user_id_active_index');

    expect((string) ($partial->indexdef ?? ''))->toContain('revoked_at IS NULL');
});
