<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Logging\TechnicalLogger;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\PushMessage;
use App\Models\RecipientContact;
use App\Services\Notifications\Channels\FakePushProvider;
use App\Services\Notifications\Channels\FcmPushProvider;
use App\Services\Notifications\Channels\PushProvider;
use App\Services\Notifications\DeviceTokens\DeviceTokenStore;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Интеграция FCM push с отправкой и замещением каналов (FR-NTF-523…528)
|--------------------------------------------------------------------------
|
| Сети нет: ответы FCM подменены `Http::fake()`, почта — `Mail::fake()`.
| Время фиксируется `Carbon::setTestNow`, поэтому плановая задержка повтора
| проверяется без ожиданий.
|
*/

uses(RefreshDatabase::class);

const FCM_INTEGRATION_USER = 7;

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    config([
        'notifications.enabled_channels' => ['email', 'push'],
        'notifications.preferences.channel_priority' => ['push', 'email'],
        'notifications.preferences.max_fallback_depth' => 3,
        'notifications.preferences.fallback_error_codes' => [
            'TELEGRAM_CHAT_NOT_FOUND',
            'TELEGRAM_REQUEST_REJECTED',
            'NOTIFICATION_CHANNEL_UNAVAILABLE',
            'NOTIFICATION_PROVIDER_REJECTED',
            'NOTIFICATION_DEVICE_UNREGISTERED',
            'NOTIFICATION_PUSH_TOKEN_MISSING',
        ],
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
        'notifications.sender.max_attempts' => 5,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Привязывает FCM-провайдер к контейнеру.
 */
function bindFcmIntegrationProvider(int $retryTimes = 0): void
{
    $sleeper = static function (int $milliseconds): void {};

    app()->instance(PushProvider::class, new FcmPushProvider(
        deviceTokens: app(DeviceTokenStore::class),
        accessToken: static fn (): string => 'ya29.test',
        logger: app(TechnicalLogger::class),
        projectId: 'demo-project',
        timeoutSeconds: 5,
        retryTimes: $retryTimes,
        retrySleepMs: 0,
        sleeper: $sleeper,
    ));
}

/**
 * Активный токен устройства получателя.
 */
function fcmIntegrationToken(int $userId = FCM_INTEGRATION_USER): DeviceToken
{
    $token = 'fcm-token-'.str_repeat('a', 40);

    return DeviceToken::factory()->create([
        'user_id' => $userId,
        'token' => $token,
        'fingerprint' => DeviceToken::fingerprint($token),
    ]);
}

/**
 * Уведомление канала push для получателя.
 */
function fcmIntegrationNotification(int $userId = FCM_INTEGRATION_USER): Notification
{
    return Notification::factory()->push()->create([
        'recipient_id' => $userId,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
        'correlation_id' => 'corr-00000042',
    ]);
}

it('отправляет push через FCM и не пишет журнал фейкового провайдера', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1'])]);

    bindFcmIntegrationProvider();
    fcmIntegrationToken();

    $notification = fcmIntegrationNotification();
    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Sent)
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and(PushMessage::query()->count())->toBe(0);

    Http::assertSent(static fn ($request): bool => $request['message']['token'] === 'fcm-token-'.str_repeat('a', 40));
});

it('отзывает токен при 410 UNREGISTERED и переходит к замещению без повтора', function (): void {
    Http::fake([
        'fcm.googleapis.com/*' => Http::response(
            ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]],
            404,
        ),
    ]);

    bindFcmIntegrationProvider();
    $device = fcmIntegrationToken();

    RecipientContact::factory()->create([
        'user_id' => FCM_INTEGRATION_USER,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    $notification = fcmIntegrationNotification();
    $status = app(NotificationSender::class)->send($notification);

    $notification->refresh();

    expect($status)->toBe(NotificationStatus::Failed)
        ->and($device->refresh()->revoked_at)->not->toBeNull()
        ->and($notification->attempts)->toBe(1)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::NOTIFICATION_DEVICE_UNREGISTERED);

    $email = Notification::query()->where('channel', 'email')->sole();

    expect((int) $email->fallback_from_notification_id)->toBe((int) $notification->getKey());

    Http::assertSentCount(1);
});

it('ставит плановую задержку по Retry-After при 429', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response('', 429, ['Retry-After' => '30'])]);

    bindFcmIntegrationProvider();
    fcmIntegrationToken();

    $notification = fcmIntegrationNotification();
    $status = app(NotificationSender::class)->send($notification);

    $notification->refresh();

    expect($status)->toBe(NotificationStatus::Error)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::PUSH_PROVIDER_RATE_LIMITED)
        ->and($notification->next_attempt_at)->not->toBeNull()
        ->and(Carbon::parse((string) $notification->next_attempt_at)->equalTo(Carbon::parse('2026-11-07 10:00:30', 'UTC')))->toBeTrue();

    $this->assertDatabaseMissing('dlq_entries', ['notification_id' => $notification->id]);
});

it('считает таймаут инфраструктурным повтором без плановой задержки', function (): void {
    Http::fake(static fn (): never => throw new ConnectionException('timeout'));

    bindFcmIntegrationProvider();
    fcmIntegrationToken();

    $notification = fcmIntegrationNotification();
    $status = app(NotificationSender::class)->send($notification);

    $notification->refresh();

    expect($status)->toBe(NotificationStatus::Error)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::PUSH_PROVIDER_UNAVAILABLE)
        ->and($notification->next_attempt_at)->toBeNull();
});

it('не создаёт DLQ, когда у получателя нет токена, и замещает канал', function (): void {
    Http::fake();

    bindFcmIntegrationProvider();

    RecipientContact::factory()->create([
        'user_id' => FCM_INTEGRATION_USER,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    $notification = fcmIntegrationNotification();
    $status = app(NotificationSender::class)->send($notification);

    $notification->refresh();

    expect($status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::NOTIFICATION_PUSH_TOKEN_MISSING);

    $email = Notification::query()->where('channel', 'email')->sole();

    expect((int) $email->fallback_from_notification_id)->toBe((int) $notification->getKey());

    // «Нет токена» — не отказ канала: записи DLQ нет (FR-NTF-528).
    $this->assertDatabaseMissing('dlq_entries', ['notification_id' => $notification->id]);

    Http::assertNothingSent();
});

it('сохраняет поведение M12 при провайдере fake без токенов', function (): void {
    Http::fake();

    app()->instance(PushProvider::class, new FakePushProvider);

    $notification = fcmIntegrationNotification();
    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Sent)
        ->and(PushMessage::query()->count())->toBe(1);

    Http::assertNothingSent();
});
