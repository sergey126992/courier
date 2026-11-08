<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Mail\NotificationMail;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\RecipientContact;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakePushProvider;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\PushProvider;
use App\Services\Notifications\Channels\TelegramApiException;
use App\Services\Notifications\NotificationCounters;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Замещение недоступного канала следующим (FR-NTF-477…482, ЧТЗ 9.3 №2)
|--------------------------------------------------------------------------
|
| Сети нет: Telegram подменён фейковым клиентом, push — фейковым провайдером,
| почта — Mail::fake(). Время фиксируется Carbon, поэтому backoff и плановая
| задержка проверяются без ожиданий (ЧТЗ 9.5).
|
*/

uses(RefreshDatabase::class);

const FALLBACK_USER = 7;

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.preferences.channel_priority' => ['push', 'telegram', 'email'],
        'notifications.preferences.max_fallback_depth' => 3,
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
 * Активная привязка Telegram получателя.
 */
function fallbackBinding(int $userId = FALLBACK_USER): ChannelBinding
{
    return ChannelBinding::factory()->create([
        'user_id' => $userId,
        'channel' => 'telegram',
        'address' => '777',
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);
}

/**
 * Контакт email получателя.
 */
function fallbackContact(int $userId = FALLBACK_USER): RecipientContact
{
    return RecipientContact::factory()->create([
        'user_id' => $userId,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);
}

/**
 * Фейковый клиент Bot API в контейнере.
 */
function fallbackTelegramClient(): FakeTelegramBotClient
{
    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    return $client;
}

/**
 * Фейковый push-провайдер, детерминированно отказывающий списку получателей.
 *
 * @param list<int> $recipients
 */
function fallbackPushProvider(array $recipients): FakePushProvider
{
    $provider = new FakePushProvider;
    $provider->failRecipients = $recipients;
    app()->instance(PushProvider::class, $provider);

    return $provider;
}

/**
 * Сохранённая строка предпочтений получателя.
 *
 * @param list<string> $channels
 */
function fallbackPreference(int $userId, string $eventType, array $channels): void
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

/**
 * Уведомление канала Telegram для получателя.
 */
function fallbackTelegramNotification(int $userId = FALLBACK_USER): Notification
{
    return Notification::factory()->telegram()->create([
        'recipient_id' => $userId,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
        'correlation_id' => 'corr-00000042',
    ]);
}

it('замещает Telegram письмом при ответе 400 и отзывает привязку', function (): void {
    $binding = fallbackBinding();
    fallbackContact();
    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');

    $notification = fallbackTelegramNotification();

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Failed);

    $notification->refresh();
    $email = Notification::query()->where('channel', 'email')->sole();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND)
        ->and($binding->refresh()->revoked_at)->not->toBeNull()
        ->and($email->status)->toBe(NotificationStatus::Created)
        ->and((int) $email->fallback_from_notification_id)->toBe((int) $notification->getKey())
        ->and($email->event_id)->toBe($notification->event_id)
        ->and($email->recipient_id)->toBe(FALLBACK_USER);

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->getKey(),
        'attempt' => 1,
        'status' => 'failed',
        'error_class' => 'business',
        'error_code' => NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND,
    ]);

    // Первый проход только создал строку: письмо уходит следующим проходом.
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect($email->refresh()->status)->toBe(NotificationStatus::Sent);
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('sender@example.test'));
});

it('не создаёт новую строку, когда цепочка длиннее предела глубины', function (): void {
    config(['notifications.preferences.max_fallback_depth' => 1]);
    fallbackBinding();
    fallbackContact();
    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');

    $notification = fallbackTelegramNotification();

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Failed)
        ->and(Notification::query()->count())->toBe(1);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND);
});

it('не создаёт второй fallback-строки и второго письма при повторной обработке события', function (): void {
    fallbackBinding();
    fallbackContact();
    fallbackPreference(FALLBACK_USER, 'order.delivered', ['telegram']);

    // event_id обязан быть UUID, courier_id и assignment_id — обязательные
    // поля схемы контракта order.delivered.v1.
    $event = [
        'event_id' => '7f2f0f9e-0000-4000-8000-00000000fa01',
        'courier_id' => 55,
        'assignment_id' => 3,
    ];
    fakeEventStream([notificationStreamMessage('order.delivered', $event)]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(1);

    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->where('channel', 'email')->count())->toBe(1);

    Mail::assertSent(NotificationMail::class, 1);

    // Повторное событие с тем же event_id, но новым message_id: ключ
    // дедупликации не даёт второй строки и второго письма (сценарий 16).
    fakeEventStream([notificationStreamMessage('order.delivered', $event, 'message-repeat-1')]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->where('channel', 'email')->count())->toBe(1);

    Mail::assertSent(NotificationMail::class, 1);
});

it('оставляет исходную строку failed и одно обратное событие, когда цепочка исчерпана', function (): void {
    // Ни привязки Telegram, ни контакта email: следующего канала нет.
    fallbackPushProvider([FALLBACK_USER]);

    $notification = Notification::factory()->push()->create([
        'recipient_id' => FALLBACK_USER,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
        'correlation_id' => 'corr-00000042',
    ]);

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Failed)
        ->and(Notification::query()->count())->toBe(1);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::NOTIFICATION_PROVIDER_REJECTED)
        ->and(DB::table('outbox_messages')->where('event_type', 'notification.failed')->count())->toBe(1);
});

it('не запускает fallback при 429 и сохраняет плановую задержку повтора', function (): void {
    fallbackBinding();
    fallbackContact();
    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 429, 'Too Many Requests', 30);

    $notification = fallbackTelegramNotification();

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Error)
        ->and(Notification::query()->count())->toBe(1);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_RATE_LIMITED)
        ->and(DB::table('notifications')->where('id', $notification->getKey())->value('next_attempt_at'))->not->toBeNull();
});

it('проходит цепочку push, telegram и email при детерминированных отказах', function (): void {
    fallbackBinding();
    fallbackContact();
    fallbackPushProvider([FALLBACK_USER]);
    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');

    $push = Notification::factory()->push()->create([
        'recipient_id' => FALLBACK_USER,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]);

    app(NotificationSender::class)->send($push);

    $telegram = Notification::query()->where('channel', 'telegram')->sole();

    expect($push->refresh()->status)->toBe(NotificationStatus::Failed)
        ->and((int) $telegram->fallback_from_notification_id)->toBe((int) $push->getKey());

    app(NotificationSender::class)->send($telegram);

    $email = Notification::query()->where('channel', 'email')->sole();

    expect($telegram->refresh()->status)->toBe(NotificationStatus::Failed)
        ->and((int) $email->fallback_from_notification_id)->toBe((int) $telegram->getKey());

    app(NotificationSender::class)->send($email);

    expect($email->refresh()->status)->toBe(NotificationStatus::Sent);

    Mail::assertSent(NotificationMail::class, 1);
});

it('держит ссылку fallback_from_notification_id без внешнего ключа', function (): void {
    $column = DB::selectOne(
        'select data_type, is_nullable from information_schema.columns'
        ." where table_schema = 'public' and table_name = 'notifications'"
        ." and column_name = 'fallback_from_notification_id'",
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('bigint')
        ->and($column->is_nullable)->toBe('YES');

    $foreignKeys = DB::select(
        'select c.conname from pg_constraint c'
        .' join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey)'
        ." where c.conrelid = 'notifications'::regclass and c.contype = 'f'"
        ." and a.attname = 'fallback_from_notification_id'",
    );

    expect($foreignKeys)->toBe([]);
});

it('не упоминает причину fallback в текстах уведомлений', function (): void {
    $text = (string) file_get_contents(lang_path('ru/notifications.php'));

    foreach ([
        'fallback',
        NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND,
        NotificationErrorCode::TELEGRAM_REQUEST_REJECTED,
        NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE,
        NotificationErrorCode::NOTIFICATION_PROVIDER_REJECTED,
    ] as $needle) {
        expect(mb_stripos($text, $needle))->toBeFalse("Текст уведомлений содержит «{$needle}»");
    }
});

it('считает срабатывания fallback по каналам', function (): void {
    fallbackBinding();
    fallbackContact();
    fallbackPushProvider([FALLBACK_USER]);

    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');

    // Цепочка push → telegram → email: два срабатывания замещения.
    app(NotificationSender::class)->send(Notification::factory()->push()->create([
        'recipient_id' => FALLBACK_USER,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ]));

    $telegram = Notification::query()->where('channel', 'telegram')->sole();

    app(NotificationSender::class)->send($telegram);

    expect(app(NotificationCounters::class)->fallbacks())->toBe([
        ['channel' => 'push', 'status' => 'fallback', 'count' => 1],
        ['channel' => 'telegram', 'status' => 'fallback', 'count' => 1],
    ]);
});

it('не считает замещение, когда вторую строку не создали повтор или предел', function (): void {
    config(['notifications.preferences.max_fallback_depth' => 1]);
    fallbackBinding();
    fallbackContact();

    $client = fallbackTelegramClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: chat not found');

    app(NotificationSender::class)->send(fallbackTelegramNotification());

    expect(app(NotificationCounters::class)->fallbacks())->toBe([]);
});
