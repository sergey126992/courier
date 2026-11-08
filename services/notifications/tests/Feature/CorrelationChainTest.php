<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\RecipientContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Сквозной correlation_id (FR-OPS-201, FR-NTF-281)
|--------------------------------------------------------------------------
|
| Цепочка проверяется по частям: монолит кладёт request_id запроса в
| outbox_messages (проверка монолита), сервис переносит correlation_id
| события в строку журнала, обратное событие и лог-контекст. Полный прогон
| двух приложений требует обоих контейнеров, поэтому здесь проверяется
| сервисная часть цепочки.
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
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('переносит correlation_id события заказа в строку журнала уведомлений', function (): void {
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);

    fakeEventStream([
        notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3]),
    ]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    $notification = Notification::query()->where('channel', 'email')->first();

    if ($notification === null) {
        throw new RuntimeException('Уведомление по событию не создано.');
    }

    expect($notification->correlation_id)->toBe('corr-00000001');
});

it('возвращает correlation_id в обратном событии о доставке', function (): void {
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);

    fakeEventStream([
        notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3]),
    ]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $message = DB::table('outbox_messages')->where('event_type', 'notification.sent')->first();

    if ($message === null) {
        throw new RuntimeException('Обратное событие о доставке не создано.');
    }

    expect($message->correlation_id)->toBe('corr-00000001');

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $message->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['correlation_id'])->toBe('corr-00000001')
        ->and($payload['notification_id'])->toBeInt();
});

it('принимает correlation_id запроса монолита в лог-контекст API', function (): void {
    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $record) use ($logs): void {
        $logs->append($record);
    });

    $notification = Notification::factory()->create([
        'status' => 'failed',
        'attempts' => 1,
        'channel' => 'email',
        'correlation_id' => 'corr-api-1',
    ]);

    $response = $this->postJson(
        'api/v1/notifications/'.$notification->id.'/retry',
        [],
        [
            'Authorization' => 'Bearer '.config('notifications.api.token'),
            'X-Request-Id' => 'req-0123456789abcdef',
        ],
    );

    $response->assertStatus(202)
        ->assertHeader('X-Request-Id', 'req-0123456789abcdef');

    $record = collect($logs->getArrayCopy())->first(
        fn (MessageLogged $record): bool => ($record->context['notification_id'] ?? null) === (int) $notification->id,
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context)->toMatchArray([
        'correlation_id' => 'req-0123456789abcdef',
        'notification_id' => (int) $notification->id,
        'channel' => 'email',
        'status' => 'error',
    ]);
});
