<?php

declare(strict_types=1);

use App\Models\RecipientContact;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| GET /health (FR-NTF-214, FR-OPS-203, FR-OPS-204, FR-OPS-210)
|--------------------------------------------------------------------------
|
| Маршрут открыт без токена. 200 — база и Redis доступны и пороги не
| превышены, 503 — сбой зависимости или превышение порога. Внешний
| мониторинг ориентируется на HTTP-код, status и lag_seconds (ЧТЗ 8.6).
|
*/

uses(RefreshDatabase::class);

/**
 * Ответ XINFO GROUPS для группы сервиса.
 *
 * @return array<int, array<string, mixed>>
 */
function healthGroup(int $pending = 0, int $lag = 0): array
{
    return [[
        'name' => 'notifications-service-test',
        'consumers' => 1,
        'pending' => $pending,
        'last-delivered-id' => '0-0',
        'entries-read' => null,
        'lag' => $lag,
    ]];
}

it('отвечает 200 и ok при доступных базе и Redis', function (): void {
    bindFakeRedis(['xinfo' => healthGroup()]);

    $response = $this->getJson('/health');
    $body = (array) $response->json();

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks', ['database' => 'ok', 'redis' => 'ok', 'contracts' => 'ok'])
        ->assertJsonPath('lag_seconds', 0)
        ->assertJsonPath('pending', 0);

    expect(array_keys($body))->toBe(['status', 'checks', 'lag_seconds', 'pending', 'generated_at'])
        ->and($body['generated_at'])->toBeString();
});

it('отвечает 200 и ok, пока потока заказов ещё нет', function (): void {
    bindFakeRedis(['xinfo' => new RuntimeException('ERR no such key')]);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.redis', 'ok')
        ->assertJsonPath('lag_seconds', 0)
        ->assertJsonPath('pending', 0);
});

it('отвечает 503 и degraded при превышении порога лага', function (): void {
    bindFakeRedis([
        'xinfo' => healthGroup(pending: 0, lag: 3),
        'xrange' => [['1-0', ['occurred_at' => now()->subSeconds(900)->toIso8601String()]]],
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis', 'ok');

    expect($response->json('lag_seconds'))->toBeInt()->toBeGreaterThanOrEqual(890);
});

it('отвечает 503 и degraded при превышении числа необработанных', function (): void {
    bindFakeRedis([
        'xinfo' => healthGroup(pending: 150, lag: 150),
        'xpending' => [['1-0', 'consumer-1', 1000, 1]],
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('pending', 150);
});

it('отвечает 503 и degraded, когда Redis недоступен, и не падает', function (): void {
    bindFakeRedis([], new RuntimeException('connection refused'));

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis', 'error')
        ->assertJsonPath('lag_seconds', null)
        ->assertJsonPath('pending', null);
});

it('не раскрывает персональные данные и идентификаторы пользователей', function (): void {
    $contact = RecipientContact::factory()->create(['email' => 'secret.person@example.test']);
    bindFakeRedis(['xinfo' => healthGroup()]);

    $content = (string) $this->getJson('/health')->getContent();

    expect($content)->not->toContain($contact->email)
        ->not->toContain('contacts')
        ->not->toContain('user_id');

    expect(array_keys((array) json_decode($content, true, 512, JSON_THROW_ON_ERROR)))
        ->toBe(['status', 'checks', 'lag_seconds', 'pending', 'generated_at']);
});
