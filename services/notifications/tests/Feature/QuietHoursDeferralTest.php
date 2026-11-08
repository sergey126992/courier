<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationStatus;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\RecipientContact;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\NotificationCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Отложенная отправка в тихие часы (FR-NTF-491…497, ЧТЗ 5.4, 5.5, 9.3 №4, 5)
|--------------------------------------------------------------------------
|
| Сети нет: почта подменена, Telegram — фейковый клиент. Время фиксируется
| Carbon, поэтому окно проверяется без sleep. Хранимый момент — UTC.
|
*/

uses(RefreshDatabase::class);

const QUIET_USER = 7;
const QUIET_TOKEN = 'test-service-token';

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    config([
        'notifications.api.token' => QUIET_TOKEN,
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.preferences.channel_priority' => ['push', 'telegram', 'email'],
        'notifications.preferences.default_timezone' => 'Europe/Moscow',
        'notifications.preferences.quiet_hours_channels' => ['push', 'telegram'],
        'notifications.preferences.critical_event_types' => ['order.delivered', 'order.failed'],
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
        'notifications.sender.max_attempts' => 5,
        'notifications.sender.batch_size' => 50,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Контакт письма получателя.
 */
function quietContact(int $userId = QUIET_USER): void
{
    RecipientContact::factory()->create([
        'user_id' => $userId,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);
}

/**
 * Активная привязка Telegram получателя.
 */
function quietTelegram(int $userId = QUIET_USER): void
{
    ChannelBinding::factory()->create([
        'user_id' => $userId,
        'channel' => 'telegram',
        'address' => '777',
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);
}

/**
 * Сохранённая строка предпочтений с окном тихих часов.
 *
 * @param list<string> $channels
 * @param array<string, string>|null $quietHours
 */
function quietPreference(
    string $eventType,
    array $channels,
    ?array $quietHours = ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
    int $userId = QUIET_USER,
): void {
    NotificationPreference::query()->create([
        'user_id' => $userId,
        'event_type' => $eventType,
        'channels' => $channels,
        'quiet_hours' => $quietHours,
        'locale' => 'ru',
        'critical_override' => false,
    ]);
}

/**
 * Момент `deferred_until` строки в UTC.
 */
function quietDeferred(Notification $notification): ?string
{
    return $notification->deferred_until?->utc()->format('Y-m-d H:i:s');
}

dataset('моменты создания при окне 22:00–08:00 Europe/Moscow', [
    '21:59' => ['2026-11-07 18:59:00', null],
    '22:00' => ['2026-11-07 19:00:00', '2026-11-08 05:00:00'],
    '00:30' => ['2026-11-07 21:30:00', '2026-11-08 05:00:00'],
    '07:59' => ['2026-11-08 04:59:00', '2026-11-08 05:00:00'],
]);

it('откладывает push и telegram по таблице 5.5, а email — нет', function (string $nowUtc, ?string $expected): void {
    Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));
    quietContact();
    quietTelegram();
    quietPreference('order.cancelled', ['push', 'telegram', 'email']);

    fakeEventStream([notificationStreamMessage('order.cancelled')]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $push = Notification::query()->where('channel', 'push')->sole();
    $telegram = Notification::query()->where('channel', 'telegram')->sole();
    $email = Notification::query()->where('channel', 'email')->sole();

    expect(Notification::query()->count())->toBe(3)
        ->and(quietDeferred($push))->toBe($expected)
        ->and(quietDeferred($telegram))->toBe($expected)
        ->and($email->deferred_until)->toBeNull();
})->with('моменты создания при окне 22:00–08:00 Europe/Moscow');

it('не откладывает критичное событие даже в тихие часы', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    quietContact();
    quietTelegram();
    quietPreference('order.failed', ['telegram', 'email']);

    fakeEventStream([notificationStreamMessage('order.failed', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $rows = Notification::query()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(
            static fn (Notification $notification): bool => $notification->deferred_until === null,
        ))->toBeTrue();
});

it('берёт таймзону по умолчанию, если в окне её нет', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    quietPreference('order.cancelled', ['push'], ['from' => '22:00', 'to' => '08:00']);

    fakeEventStream([notificationStreamMessage('order.cancelled')]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $row = Notification::query()->sole();

    // Окно без таймзоны трактуется в `preferences.default_timezone`
    // (FR-NTF-491): 08:00 Europe/Moscow — это 05:00 UTC.
    expect($row->channel)->toBe('push')
        ->and(quietDeferred($row))->toBe('2026-11-08 05:00:00');
});

it('не берёт отложенную строку поллером и отправляет её после окна один раз', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    quietContact();
    quietTelegram();
    quietPreference('order.cancelled', ['push', 'telegram', 'email']);

    app()->instance(TelegramBotClient::class, new FakeTelegramBotClient);

    fakeEventStream([notificationStreamMessage('order.cancelled')]);
    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(quietDeferred(Notification::query()->where('channel', 'push')->sole()))
        ->toBe('2026-11-08 05:00:00')
        ->and(quietDeferred(Notification::query()->where('channel', 'telegram')->sole()))
        ->toBe('2026-11-08 05:00:00')
        ->and(Notification::query()->where('channel', 'email')->sole()->deferred_until)->toBeNull();

    // В тихие часы уходит только письмо (FR-NTF-496).
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(1)
        ->and(Notification::query()->where('channel', 'email')->sole()->status)
        ->toBe(NotificationStatus::Sent)
        ->and(Notification::query()->where('channel', 'push')->sole()->status)
        ->toBe(NotificationStatus::Created)
        ->and(Notification::query()->where('channel', 'telegram')->sole()->status)
        ->toBe(NotificationStatus::Created);

    // Окно закончилось: отложенные строки отправляются ровно один раз.
    Carbon::setTestNow(Carbon::parse('2026-11-08 05:00:00', 'UTC'));

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(3);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(3)
        ->and(Notification::query()->count())->toBe(3);
});

it('не дублирует отложенную строку при повторной обработке события', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    quietContact();
    quietTelegram();
    quietPreference('order.cancelled', ['push', 'telegram', 'email']);

    $message = notificationStreamMessage('order.cancelled');

    fakeEventStream([$message]);
    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $firstDeferred = quietDeferred(Notification::query()->where('channel', 'push')->sole());

    fakeEventStream([$message]);
    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    // Вторая обработка попадает в дедупликацию: строк столько же, время
    // отложенности не переписано (FR-NTF-495).
    expect(Notification::query()->count())->toBe(3)
        ->and(quietDeferred(Notification::query()->where('channel', 'push')->sole()))->toBe($firstDeferred);
});

it('не ускоряет отложенную строку ручным повтором', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    quietContact();
    quietTelegram();
    quietPreference('order.cancelled', ['push', 'telegram', 'email']);

    fakeEventStream([notificationStreamMessage('order.cancelled')]);
    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $push = Notification::query()->where('channel', 'push')->sole();

    $this->withHeaders(['Authorization' => 'Bearer '.QUIET_TOKEN])
        ->postJson('/api/v1/notifications/'.$push->id.'/retry')
        ->assertStatus(202);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    // Ручной повтор не снимает отложенность: строка ждёт окончания окна
    // (FR-NTF-495).
    expect(Notification::query()->whereKey($push->id)->sole()->status)
        ->toBe(NotificationStatus::Created);
});

it('не пересчитывает уже созданные строки при изменении тихих часов', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    quietContact();
    quietPreference('order.cancelled', ['push']);

    fakeEventStream([notificationStreamMessage('order.cancelled')]);
    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $existing = Notification::query()->where('channel', 'push')->sole();

    expect(quietDeferred($existing))->toBe('2026-11-08 05:00:00');

    NotificationPreference::query()
        ->where('user_id', QUIET_USER)
        ->where('event_type', 'order.cancelled')
        ->update(['quiet_hours' => ['from' => '21:00', 'to' => '06:00', 'timezone' => 'Europe/Moscow']]);

    // Новое окно не трогает прежнюю строку (FR-NTF-497).
    expect(quietDeferred($existing->refresh()))->toBe('2026-11-08 05:00:00');

    fakeEventStream([notificationStreamMessage('order.cancelled')]);
    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $fresh = Notification::query()->where('channel', 'push')->orderByDesc('id')->first();

    // Новое событие получает новое окно: 06:00 MSK — это 03:00 UTC.
    expect($fresh?->deferred_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 03:00:00');
});

it('считает отложенные тихими часами уведомления по каналам', function (): void {
    quietContact();
    quietTelegram();
    quietPreference('order.cancelled', ['push', 'telegram', 'email']);

    fakeEventStream([notificationStreamMessage('order.cancelled')]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    // Email окно не откладывает, поэтому счётчик только по push и telegram
    // (FR-OPS-413, FR-OPS-418).
    expect(app(NotificationCounters::class)->deferred())->toBe([
        ['channel' => 'push', 'status' => 'deferred', 'count' => 1],
        ['channel' => 'telegram', 'status' => 'deferred', 'count' => 1],
    ]);
});
