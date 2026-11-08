<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\TelegramApiException;
use App\Services\Notifications\NotificationCounters;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Канал Telegram: отправка, лимит, отзыв привязки (ЧТЗ 9.1 №8, №9)
|--------------------------------------------------------------------------
|
| Сети нет: клиент Bot API подменён фейком. Время фиксируется Carbon,
| поэтому backoff проверяется без ожиданий. `chat_id` не попадает ни
| в журнал попыток, ни в сообщения (FR-NTF-419).
|
*/

uses(RefreshDatabase::class);

const TG_CHANNEL_CHAT = '777';
const TG_CHANNEL_USER = 7;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    config([
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
        'notifications.sender.max_attempts' => 5,
        'notifications.telegram.message_limit' => 4096,
        'notifications.telegram.retry_after_default_seconds' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Фейковый клиент Bot API, подставленный в контейнер.
 */
function tgChannelClient(): FakeTelegramBotClient
{
    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    return $client;
}

/**
 * Активная привязка Telegram получателя.
 */
function tgChannelBinding(int $userId = TG_CHANNEL_USER, string $chatId = TG_CHANNEL_CHAT): ChannelBinding
{
    return ChannelBinding::factory()->create([
        'user_id' => $userId,
        'channel' => 'telegram',
        'address' => $chatId,
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);
}

/**
 * Плановая задержка строки журнала: `null` — запрета нет.
 */
function tgChannelNextAttemptAt(int $notificationId): mixed
{
    return DB::table('notifications')->where('id', $notificationId)->value('next_attempt_at');
}

/**
 * Уведомление канала Telegram.
 */
function tgChannelNotification(int $userId = TG_CHANNEL_USER): Notification
{
    return Notification::factory()->telegram()->create([
        'recipient_id' => $userId,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);
}

it('отправляет сообщение по активной привязке фейковым клиентом', function (): void {
    $client = tgChannelClient();
    tgChannelBinding();
    $notification = tgChannelNotification();

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1)
        ->and($client->sent)->toHaveCount(1)
        ->and($client->sent[0]['chat_id'])->toBe(TG_CHANNEL_CHAT)
        ->and($client->sent[0]['text'])->toContain('42')
        ->and($client->sent[0]['text'])->toContain('delivered')
        ->and($client->sent[0]['text'])->toContain('http://localhost:8000/track/TRK-42');
});

it('не создаёт отправки без активной привязки и даёт бизнес-код', function (): void {
    $client = tgChannelClient();
    ChannelBinding::factory()->create([
        'user_id' => TG_CHANNEL_USER,
        'channel' => 'telegram',
        'address' => TG_CHANNEL_CHAT,
        'verified_at' => now()->utc(),
        'revoked_at' => now()->utc(),
    ]);
    $notification = tgChannelNotification();

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE)
        ->and($client->sent)->toBe([]);

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 1,
        'status' => 'failed',
        'error_class' => 'business',
        'error_code' => NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE,
    ]);
});

it('отзывает привязку по 400 chat not found', function (): void {
    $client = tgChannelClient();
    $binding = tgChannelBinding();
    $notification = tgChannelNotification();

    $client->failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        400,
        'Bad Request: chat not found',
    );

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();
    $binding->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND)
        ->and($binding->revoked_at)->not->toBeNull();
});

it('отзывает привязку по 403 bot was blocked by the user', function (): void {
    $client = tgChannelClient();
    $binding = tgChannelBinding();
    $notification = tgChannelNotification();

    $client->failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        403,
        'Forbidden: bot was blocked by the user',
    );

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();
    $binding->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND)
        ->and($binding->revoked_at)->not->toBeNull();
});

it('оставляет привязку при 429 и назначает повтор', function (): void {
    $client = tgChannelClient();
    $binding = tgChannelBinding();
    $notification = tgChannelNotification();

    $client->failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        429,
        'Too Many Requests: retry after 30',
        30,
    );

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();
    $binding->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_RATE_LIMITED)
        ->and($binding->revoked_at)->toBeNull();

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 1,
        'status' => 'error',
        'error_class' => 'infrastructure',
        'error_code' => NotificationErrorCode::TELEGRAM_RATE_LIMITED,
    ]);
});

it('назначает повтор не раньше retry_after и не блокирует пачку при 429', function (): void {
    config(['notifications.sender.backoff_seconds' => [10]]);

    $client = tgChannelClient();
    tgChannelBinding();
    $limited = tgChannelNotification();

    $client->failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        429,
        'Too Many Requests: retry after 30',
        30,
    );

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $limited->refresh();

    expect($limited->status)->toBe(NotificationStatus::Error)
        ->and($limited->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_RATE_LIMITED)
        ->and(tgChannelNextAttemptAt((int) $limited->id))->not->toBeNull();

    // Через 29 секунд backoff уже выдержан, но retry_after ещё нет:
    // строку не берут, а соседняя в пачке отправляется.
    $client->failure = null;
    tgChannelBinding(8, '888');
    $other = tgChannelNotification(8);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:29', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect($limited->refresh()->attempts)->toBe(1)
        ->and($other->refresh()->status)->toBe(NotificationStatus::Sent);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:31', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $limited->refresh();

    expect($limited->status)->toBe(NotificationStatus::Sent)
        ->and($limited->attempts)->toBe(2)
        ->and(tgChannelNextAttemptAt((int) $limited->id))->toBeNull();
});

it('не отзывает привязку при неверном токене бота', function (): void {
    $client = tgChannelClient();
    $binding = tgChannelBinding();
    $notification = tgChannelNotification();

    $client->failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        401,
        'Unauthorized',
    );

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();
    $binding->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_API_FAILED)
        ->and($binding->revoked_at)->toBeNull();
});

it('сокращает текст по лимиту, сохраняя полную ссылку трекинга', function (): void {
    config(['notifications.telegram.message_limit' => 60]);

    $client = tgChannelClient();
    tgChannelBinding();
    tgChannelNotification();

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $text = $client->sent[0]['text'];

    expect($client->sent)->toHaveCount(1)
        ->and($text)->toContain('http://localhost:8000/track/TRK-42')
        ->and(mb_strlen($text))->toBeLessThanOrEqual(60);
});

it('не пишет chat_id в журнал попыток', function (): void {
    $client = tgChannelClient();
    tgChannelBinding();
    $notification = tgChannelNotification();

    $client->failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        400,
        'Bad Request: chat not found',
    );

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $message = (string) NotificationAttempt::query()
        ->where('notification_id', $notification->id)
        ->value('error_message');

    expect($message)->not->toBe('')
        ->and($message)->not->toContain(TG_CHANNEL_CHAT);
});

dataset('коды ответа Telegram для счётчика', [
    'нет ответа' => [0, 'timeout'],
    '400' => [400, '400'],
    '403' => [403, '403'],
    '429' => [429, '429'],
    '500' => [500, '5xx'],
    '503' => [503, '5xx'],
    '401' => [401, 'other'],
]);

it('считает ошибки Telegram по коду ответа', function (int $status, string $bucket): void {
    $client = tgChannelClient();
    tgChannelBinding();

    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', $status, 'error');

    app(NotificationSender::class)->send(tgChannelNotification());

    expect(app(NotificationCounters::class)->telegramErrors())->toBe([
        ['channel' => 'telegram', 'status' => $bucket, 'count' => 1],
    ]);
})->with('коды ответа Telegram для счётчика');

it('не пишет chat_id и токен в журнал ошибки Telegram', function (): void {
    $client = tgChannelClient();
    tgChannelBinding();
    config(['notifications.telegram.bot_token' => 'secret-bot-token-value']);

    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 429, 'Too Many Requests: retry after 30', 30);

    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, static function (MessageLogged $record) use ($logs): void {
        $logs->append($record);
    });

    app(NotificationSender::class)->send(tgChannelNotification());

    $record = collect($logs->getArrayCopy())->first(
        static fn (MessageLogged $record): bool => $record->message === 'Отправка сообщения Telegram завершилась ошибкой.',
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context)->toMatchArray([
        'channel' => 'telegram',
        'error_code' => NotificationErrorCode::TELEGRAM_RATE_LIMITED,
        'retry_after' => 30,
    ]);

    $journal = (string) json_encode(
        collect($logs->getArrayCopy())->map(
            static fn (MessageLogged $record): array => ['message' => $record->message, 'context' => $record->context],
        )->all(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    expect($journal)->not->toContain(TG_CHANNEL_CHAT)
        ->not->toContain('secret-bot-token-value')
        ->not->toContain('t.me');
});
