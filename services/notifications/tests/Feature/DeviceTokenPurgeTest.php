<?php

declare(strict_types=1);

use App\Models\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Очистка отозванных device-токенов (FR-NTF-530, ЧТЗ 7.3 п. 6)
|--------------------------------------------------------------------------
|
| Удаляются только отозванные строки старше срока: активные токены и недавно
| отозванные остаются. Проверяются ключи, коды возврата 0/2, вывод без
| полного токена и регистрация ровно одного события в расписании.
|
*/

uses(RefreshDatabase::class);

/**
 * Токен, отозванный в заданный момент.
 */
function devicePurgeToken(?string $revokedAt, string $suffix = 'a'): DeviceToken
{
    $token = 'fcm-token-'.str_repeat($suffix, 40);

    return DeviceToken::factory()->create([
        'token' => $token,
        'fingerprint' => DeviceToken::fingerprint($token),
        'revoked_at' => $revokedAt === null ? null : Carbon::parse($revokedAt, 'UTC'),
    ]);
}

beforeEach(function (): void {
    config(['notifications.device_tokens.retention_days' => 30]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('удаляет только отозванные токены старше срока', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $oldRevoked = devicePurgeToken('2026-09-01 10:00:00+00:00', 'a');
    $recentRevoked = devicePurgeToken('2026-11-06 10:00:00+00:00', 'b');
    $active = devicePurgeToken(null, 'c');

    $this->artisan('notifications:device-tokens:purge', ['--json' => true])
        ->expectsOutputToContain('"deleted":1')
        ->assertExitCode(0);

    expect(DeviceToken::query()->find($oldRevoked->id))->toBeNull()
        ->and(DeviceToken::query()->find($recentRevoked->id))->not->toBeNull()
        ->and(DeviceToken::query()->find($active->id))->not->toBeNull();
});

it('применяет срок из ключа --days', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    // Отозван 10 дней назад: при сроке 7 дней удаляется, при 30 — остаётся.
    $device = devicePurgeToken('2026-10-28 10:00:00+00:00');

    $this->artisan('notifications:device-tokens:purge', ['--days' => '7', '--json' => true])
        ->expectsOutputToContain('"deleted":1')
        ->assertExitCode(0);

    expect(DeviceToken::query()->find($device->id))->toBeNull();
});

it('использует срок из конфигурации по умолчанию', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $old = devicePurgeToken('2026-09-01 10:00:00+00:00', 'a');
    $recent = devicePurgeToken('2026-10-30 10:00:00+00:00', 'b');

    $this->artisan('notifications:device-tokens:purge', ['--json' => true])
        ->expectsOutputToContain('"days":30')
        ->assertExitCode(0);

    expect(DeviceToken::query()->find($old->id))->toBeNull()
        ->and(DeviceToken::query()->find($recent->id))->not->toBeNull();
});

it('не выводит полный токен в JSON', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $device = devicePurgeToken('2026-09-01 10:00:00+00:00', 'z');

    $this->artisan('notifications:device-tokens:purge', ['--json' => true])->assertExitCode(0);

    $output = Artisan::output();

    expect($output)->not->toContain($device->token)
        ->and($output)->not->toContain('fcm-token-');
});

it('возвращает 2 на некорректный срок хранения', function (): void {
    $this->artisan('notifications:device-tokens:purge', ['--days' => '0'])->assertExitCode(2);
    $this->artisan('notifications:device-tokens:purge', ['--days' => 'abc'])->assertExitCode(2);
});

it('регистрирует очистку токенов в расписании одним событием', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $matches = array_values(array_filter(
        $events,
        static fn (array $event): bool => str_contains(
            (string) ($event['command'] ?? ''),
            'notifications:device-tokens:purge',
        ),
    ));

    expect($matches)->toHaveCount(1);

    /** @var array<string, mixed> $event */
    $event = $matches[0];
    $cron = (string) config('notifications.retention.purge_cron');

    expect($cron)->not->toBeEmpty()
        ->and((string) $event['expression'])->toContain($cron);
});
