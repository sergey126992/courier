<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Models\NotificationDedup;
use App\Models\NotificationPreference;
use App\Models\RecipientContact;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Подбор каналов по предпочтениям и пустая цепочка (FR-NTF-471…476, ЧТЗ 9.3)
|--------------------------------------------------------------------------
|
| Событие → предпочтения → строки нужных каналов → фейковая отправка.
| Сети нет: почта подменена, Telegram — фейковый клиент (ЧТЗ 9.7).
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.preferences.channel_priority' => ['push', 'telegram', 'email'],
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
 * Сохранённая строка предпочтений получателя.
 *
 * @param list<string> $channels
 */
function selectionPreference(int $userId, string $eventType, array $channels): void
{
    NotificationPreference::query()->create([
        'user_id' => $userId,
        'event_type' => $eventType,
        'channels' => $channels,
        'quiet_hours' => null,
        'locale' => 'ru',
        'critical_override' => false,
    ]);
}

it('создаёт строки выбранных каналов и отправляет их с обратным событием', function (): void {
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);
    ChannelBinding::factory()->create([
        'user_id' => 7,
        'channel' => 'telegram',
        'address' => '777',
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);
    selectionPreference(7, 'order.delivered', ['telegram', 'email']);

    fakeEventStream([notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $channels = Notification::query()->orderBy('id')->pluck('channel')->all();

    expect($channels)->toBe(['telegram', 'email'])
        ->and(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->where('status', NotificationStatus::Created->value)->count())->toBe(2)
        ->and(NotificationDedup::query()->count())->toBe(2);

    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->where('status', NotificationStatus::Sent->value)->count())->toBe(2)
        ->and($client->sent)->toHaveCount(1)
        ->and(DB::table('outbox_messages')->where('event_type', 'notification.sent')->count())->toBe(2);
});

it('создаёт одну строку failed и обратное событие при пустой цепочке', function (): void {
    selectionPreference(7, 'order.delivered', ['telegram']);

    fakeEventStream([notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    // Канал выбран, но активной привязки Telegram нет: строка created не
    // создаётся, вместо неё одна строка failed (FR-NTF-474, FR-NTF-476).
    $failed = Notification::query()->get();

    expect($failed)->toHaveCount(1)

        ->and($failed->every(
            static fn (Notification $notification): bool => $notification->status === NotificationStatus::Failed
                && $notification->last_error_code === NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE
                && $notification->channel === 'telegram',
        ))->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_type', 'notification.failed')->count())->toBe(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) DB::table('outbox_messages')->value('payload'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['error_code'])->toBe(NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->where('status', NotificationStatus::Failed->value)->count())->toBe(1);
});

it('не создаёт строк и обратного события для отключённого типа события', function (): void {
    selectionPreference(7, 'order.picked_up', []);

    fakeEventStream([notificationStreamMessage('order.picked_up', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(0)
        ->and(NotificationDedup::query()->count())->toBe(0)
        ->and(DB::table('outbox_messages')->count())->toBe(0);
});

it('читает предпочтения получателя одним запросом', function (): void {
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);

    $queries = 0;

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'notification_preferences')) {
            $queries++;
        }
    });

    fakeEventStream([notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect($queries)->toBe(1);
});
