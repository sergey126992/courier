<?php

declare(strict_types=1);

use App\Logging\TechnicalLogFormatter;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Models\RecipientContact;
use App\Services\Events\ContactEventConsumer;
use App\Services\Events\EventStreamConsumer;
use App\Services\Events\StreamMessage;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\TelegramApiException;
use App\Services\Notifications\NotificationPoller;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guard журнала: только технические поля и сквозной correlation_id (FR-OPS-202, FR-OPS-208)
|--------------------------------------------------------------------------
|
| Сценарии с настоящим контактом и настоящим письмом пишут записи в журнал:
| адрес, телефон, токен и тело письма в них попасть не должны. Лог-контекст
| записи содержит correlation_id, event_id, notification_id, order_id,
| recipient_id, channel, status, attempt — и ничего сверх белого списка.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'UTC'));
    config([
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
        'notifications.sender.max_attempts' => 5,
        'notifications.sender.batch_size' => 50,
    ]);

    // Сбор записей журнала: слушатель ставится на настоящий диспетчер,
    // поэтому записи перехватываются независимо от момента создания логгера.
    $GLOBALS['technicalLogs'] = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $record): void {
        $GLOBALS['technicalLogs']->append($record);
    });
});

afterEach(function (): void {
    unset($GLOBALS['technicalLogs']);
    Carbon::setTestNow();
});

/**
 * Захваченные записи журнала.
 *
 * @return Collection<int, MessageLogged>
 */
function capturedLogs(): Collection
{
    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = $GLOBALS['technicalLogs'];

    return collect($logs->getArrayCopy())->values();
}

it('не пишет адрес, телефон и токен при обработке контакта и отправке письма', function (): void {
    $stream = fakeEventStream([
        contactStreamMessage(9, 'guard.person@example.test'),
    ]);

    app(ContactEventConsumer::class)->consume('events.contacts', 'guard-consumer', 10);

    // Уведомление email отправляется настоящим каналом с подменённой почтой.
    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'guard.person@example.test',
        'locale' => 'ru',
    ]);

    Notification::factory()->create([
        'recipient_id' => 7,
        'channel' => 'email',
        'template' => 'order_delivered_email',
        'event_type' => 'order.delivered',
        'correlation_id' => 'corr-guard-1',
    ]);

    app(NotificationPoller::class)->run(10);

    $journal = json_encode(
        capturedLogs()->map(fn (MessageLogged $record): array => [
            'level' => $record->level,
            'message' => $record->message,
            'context' => $record->context,
        ])->all(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    expect($journal)->not->toContain('guard.person@example.test')
        ->not->toContain('@example.test')
        ->not->toContain('dev-notifications-token')
        ->not->toContain('+7 999');

    expect($stream->acknowledged)->toBe(['0-1']);
});

it('содержит в контексте отправки технические поля и correlation_id', function (): void {
    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'guard.person@example.test',
        'locale' => 'ru',
    ]);

    $notification = Notification::factory()->create([
        'recipient_id' => 7,
        'order_id' => 42,
        'channel' => 'email',
        'template' => 'order_delivered_email',
        'event_type' => 'order.delivered',
        'correlation_id' => 'corr-guard-2',
    ]);

    app(NotificationPoller::class)->run(10);

    $record = capturedLogs()->first(
        fn (MessageLogged $record): bool => ($record->context['notification_id'] ?? null) === (int) $notification->getKey(),
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context)->toMatchArray([
        'correlation_id' => 'corr-guard-2',
        'notification_id' => (int) $notification->getKey(),
        'order_id' => 42,
        'recipient_id' => 7,
        'channel' => 'email',
        'status' => 'sent',
        'attempt' => 1,
    ]);

    $extra = array_diff(array_keys($record->context), TechnicalLogFormatter::ALLOWED_FIELDS);

    expect($extra)->toBe([]);
});

it('пишет счётчики отправленных, необработанных и повторённых уведомлений по каналам', function (): void {
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);

    Notification::factory()->create(['recipient_id' => 7, 'channel' => 'email', 'status' => 'created']);
    Notification::factory()->push()->create([
        'recipient_id' => 8,
        'status' => 'error',
        'attempts' => 2,
    ]);
    Notification::factory()->create(['recipient_id' => 9, 'channel' => 'push', 'status' => 'failed', 'attempts' => 1]);
    Notification::factory()->sent()->create(['recipient_id' => 10, 'channel' => 'email']);

    app(NotificationPoller::class)->run(1);

    $counters = capturedLogs()->filter(
        fn (MessageLogged $record): bool => array_key_exists('count', $record->context),
    );

    expect($counters)->not->toBeEmpty();

    $pairs = $counters->map(fn (MessageLogged $record): string => $record->context['channel'].':'.$record->context['status'])->all();

    expect($pairs)->toContain('email:sent')
        ->and($pairs)->toContain('push:failed')
        ->and($pairs)->toContain('push:retried');
});

it('связывает событие потока с лог-контекстом потребителя', function (): void {
    // Сбой эффекта: потребитель пишет запись в области контекста события
    // (correlation_id и event_id события заказа), адресов в ней нет.
    $messageId = (string) Str::uuid();
    $fields = notificationEventFields('order.delivered', ['courier_id' => 55, 'assignment_id' => 3], $messageId);
    $fields['correlation_id'] = 'corr-event-1';

    fakeEventStream([new StreamMessage('0-1', $fields)]);

    $result = app(EventStreamConsumer::class)->consume(
        'events.orders',
        'guard-consumer',
        10,
        static function (): int {
            throw new RuntimeException('сбой эффекта');
        },
    );

    expect($result->failed)->toBe(1);

    $record = capturedLogs()->first(
        fn (MessageLogged $record): bool => ($record->context['error_code'] ?? null) === 'EVENT_CONSUMER_FAILED',
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context)->toMatchArray([
        'correlation_id' => 'corr-event-1',
        'event_id' => $messageId,
    ]);
});

it('пишет замещение канала без chat_id, адреса и deep-link', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.preferences.channel_priority' => ['push', 'telegram', 'email'],
        'notifications.preferences.max_fallback_depth' => 3,
        'notifications.telegram.message_limit' => 4096,
        'notifications.telegram.retry_after_default_seconds' => 30,
    ]);

    ChannelBinding::factory()->create([
        'user_id' => 7,
        'channel' => 'telegram',
        'address' => 'guard-chat-777',
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);

    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'guard.fallback@example.test',
        'locale' => 'ru',
    ]);

    $client = new FakeTelegramBotClient;
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');
    app()->instance(TelegramBotClient::class, $client);

    $notification = Notification::factory()->telegram()->create([
        'recipient_id' => 7,
        'order_id' => 42,
        'tracking_code' => 'TRK-GUARD-1',
        'event_type' => 'order.delivered',
        'correlation_id' => 'corr-guard-3',
    ]);

    app(NotificationSender::class)->send($notification);

    $record = capturedLogs()->first(
        fn (MessageLogged $record): bool => $record->message === 'Канал уведомления заменён следующим.',
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context)->toMatchArray([
        'correlation_id' => 'corr-guard-3',
        'user_id' => 7,
        'channel' => 'email',
        'event_type' => 'order.delivered',
        'fallback_from' => 'telegram',
    ]);

    expect(array_diff(array_keys($record->context), TechnicalLogFormatter::ALLOWED_FIELDS))->toBe([]);

    $journal = (string) json_encode(
        capturedLogs()->map(static fn (MessageLogged $record): array => [
            'message' => $record->message,
            'context' => $record->context,
        ])->all(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    expect($journal)->not->toContain('guard-chat-777')
        ->not->toContain('guard.fallback@example.test')
        ->not->toContain('t.me');
});

it('пишет ошибку вебхука без секрета, chat_id и токена', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'guard-bot-token',
        'notifications.telegram.bot_username' => 'courier_test_bot',
        'notifications.telegram.updates_mode' => 'webhook',
        'notifications.telegram.webhook_secret' => 'guard-webhook-secret',
    ]);

    // Маршрут вебхука регистрируется только в режиме `webhook`.
    Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api.php'));

    $client = new FakeTelegramBotClient;
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 500, 'Internal Server Error');
    app()->instance(TelegramBotClient::class, $client);

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'guard-webhook-secret'])
        ->postJson('/api/v1/telegram/webhook', [
            'update_id' => 900,
            'message' => [
                'message_id' => 900,
                'chat' => ['id' => 777000111, 'type' => 'private'],
                'text' => '/start guard-link-token',
            ],
        ])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    $record = capturedLogs()->first(
        fn (MessageLogged $record): bool => $record->message === 'Обработка апдейта Telegram завершилась ошибкой.',
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect(array_diff(array_keys($record->context), TechnicalLogFormatter::ALLOWED_FIELDS))->toBe([]);

    $journal = (string) json_encode(
        capturedLogs()->map(static fn (MessageLogged $record): array => [
            'message' => $record->message,
            'context' => $record->context,
        ])->all(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    expect($journal)->not->toContain('guard-webhook-secret')
        ->not->toContain('guard-bot-token')
        ->not->toContain('guard-link-token')
        ->not->toContain('777000111')
        ->not->toContain('t.me');
});
