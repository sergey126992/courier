<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Models\RecipientContact;
use App\Services\Events\Contracts\EventStream;
use App\Services\Notifications\NotificationSender;
use App\Services\Notifications\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Команда публикатора Outbox сервиса (ЧТЗ 8.6, 9.2)
|--------------------------------------------------------------------------
|
| Проверяются ключи --limit/--json, коды 0/1/2, перерисовка успешного,
| неудачного и занятого блокировкой прохода, ошибка конфигурации и
| исключение. Транспорт подменяется FakeEventStream, сеть не нужна.
|
*/

uses(RefreshDatabase::class);

/**
 * Уведомление email с контактом, чья отправка пишет обратное событие.
 */
function publishOutboxNotification(): Notification
{
    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    return Notification::factory()->create([
        'recipient_id' => 7,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
        'correlation_id' => 'corr-00000042',
    ]);
}

it('возвращает 2 на некорректный --limit в текстовом и JSON-выводе', function (): void {
    $this->artisan('notifications:publish-outbox', ['--limit' => 'abc'])
        ->expectsOutputToContain('--limit')
        ->assertExitCode(2);

    $this->artisan('notifications:publish-outbox', ['--limit' => '0', '--json' => true])
        ->expectsOutputToContain('"error"')
        ->assertExitCode(2);
});

it('принимает корректный --limit и печатает пустой проход', function (): void {
    fakeEventStream();

    $this->artisan('notifications:publish-outbox', ['--limit' => '5'])
        ->expectsOutputToContain('Опубликовано событий: 0, неудачных: 0.')
        ->assertExitCode(0);
});

it('публикует пачку и печатает JSON-счётчики', function (): void {
    fakeEventStream();
    Mail::fake();

    app(NotificationSender::class)->send(publishOutboxNotification());

    expect(Artisan::call('notifications:publish-outbox', ['--json' => true]))->toBe(0);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray(['published' => 1, 'failed' => 0, 'lock_busy' => false]);
});

it('возвращает 1, когда публикация пачки не удалась', function (): void {
    $stream = fakeEventStream();
    Mail::fake();

    app(NotificationSender::class)->send(publishOutboxNotification());
    $stream->available = false;

    $this->artisan('notifications:publish-outbox')
        ->expectsOutputToContain('неудачных: 1')
        ->assertExitCode(1);
});

it('сообщает о занятой блокировке прохода', function (): void {
    fakeEventStream();
    Mail::fake();

    app(NotificationSender::class)->send(publishOutboxNotification());

    $lock = Cache::lock(OutboxPublisher::LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('notifications:publish-outbox')
            ->expectsOutput('Публикацию уже выполняет другой воркер.')
            ->assertExitCode(0);

        expect(Artisan::call('notifications:publish-outbox', ['--json' => true]))->toBe(0);
        expect(trim(Artisan::output()))->toContain('"lock_busy":true');
    } finally {
        $lock->release();
    }
});

it('возвращает 2 при исключении транспорта во время прохода', function (): void {
    Mail::fake();

    app(NotificationSender::class)->send(publishOutboxNotification());

    app()->instance(EventStream::class, new class implements EventStream
    {
        public function ensureGroup(string $stream, string $group): bool
        {
            return true;
        }

        public function read(string $stream, string $group, string $consumer, int $limit): ?array
        {
            return $limit > 0 ? [] : null;
        }

        public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit): ?array
        {
            return $limit > 0 ? [] : null;
        }

        public function acknowledge(string $stream, string $group, string $messageId): bool
        {
            return true;
        }

        public function publish(string $stream, array $fields): bool
        {
            throw new RuntimeException('boom');
        }
    });

    expect(OutboxMessage::query()->count())->toBe(1);

    $this->artisan('notifications:publish-outbox')
        ->expectsOutputToContain('ошибкой')
        ->assertExitCode(2);

    expect(Artisan::call('notifications:publish-outbox', ['--json' => true]))->toBe(2)
        ->and(trim(Artisan::output()))->toContain('"error"');
});
