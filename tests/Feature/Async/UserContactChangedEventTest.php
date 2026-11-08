<?php

declare(strict_types=1);

use App\Actions\Identity\RegisterSender;
use App\Actions\Identity\UpdateOwnProfile;
use App\Domain\Events\EventType;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Events\EventStreamRouter;
use App\Services\Events\OutboxPublisher;
use App\Services\Events\UserContactChangedOutbox;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('пишет событие контактов при регистрации', function (): void {
    $user = app(RegisterSender::class)->handle('Иван', 'sender@example.test', 'password-1');

    $message = OutboxMessage::query()->where('event_type', 'user.contact_changed')->sole();

    expect($message->aggregate_type)->toBe('user')
        ->and($message->aggregate_id)->toBe($user->id)
        ->and($message->correlation_id)->not->toBeNull()
        ->and($message->payload)->toMatchArray([
            // event_id равен message_id строки Outbox (FR-NTF-223, ЧТЗ 8.3).
            'event_id' => $message->message_id,
            'user_id' => $user->id,
            'email' => 'sender@example.test',
            'locale' => 'ru',
        ])
        ->and($message->payload['occurred_at'])->toBeString();
});

it('откатывает событие контактов вместе с регистрацией', function (): void {
    try {
        DB::transaction(function (): void {
            app(RegisterSender::class)->handle('Иван', 'rollback@example.test', 'password-1');

            throw new RuntimeException('Откат транзакции регистрации.');
        });
    } catch (RuntimeException) {
        // Ожидаемый откат: проверяется, что событие живёт в той же транзакции.
    }

    expect(User::query()->where('email', 'rollback@example.test')->doesntExist())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'user.contact_changed')->count())->toBe(0);
});

it('пишет событие контактов при смене почты', function (): void {
    $user = User::factory()->sender()->create(['email' => 'old@example.test']);

    app(UpdateOwnProfile::class)->handle($user, ['email' => 'New@Example.TEST']);

    $message = OutboxMessage::query()->where('event_type', 'user.contact_changed')->sole();

    expect($user->refresh()->email)->toBe('new@example.test')
        ->and($message->aggregate_type)->toBe('user')
        ->and($message->aggregate_id)->toBe($user->id)
        ->and($message->payload['email'])->toBe('new@example.test')
        ->and($message->payload['event_id'])->toBe($message->message_id);
});

it('не пишет событие, если почта не изменилась', function (): void {
    $user = User::factory()->sender()->create(['email' => 'same@example.test']);

    app(UpdateOwnProfile::class)->handle($user, ['email' => 'Same@Example.test']);

    expect(OutboxMessage::query()->where('event_type', 'user.contact_changed')->count())->toBe(0)
        ->and($user->refresh()->email)->toBe('same@example.test');
});

it('публикует контакты в отдельный поток, а не в поток заказов', function (): void {
    $router = app(EventStreamRouter::class);

    expect($router->streamFor(EventType::UserContactChanged))->toBe((string) config('events.streams.contacts'))
        ->and($router->streamFor(EventType::UserContactChanged))->not->toBe((string) config('events.streams.orders'))
        ->and($router->streamFor(EventType::OrderCreated))->toBe((string) config('events.streams.orders'))
        ->and($router->streamFor(EventType::CourierShiftStarted))->toBe((string) config('events.streams.orders'));
});

it('отправляет событие контактов через in-memory транспорт и не пишет email в журнал', function (): void {
    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
    });

    $user = User::factory()->sender()->create(['email' => 'secret.sender@example.test']);

    app(UserContactChangedOutbox::class)->record($user);

    $result = app(OutboxPublisher::class)->run();

    $message = OutboxMessage::query()->where('event_type', 'user.contact_changed')->sole();

    expect($result->published)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($message->published_at)->not->toBeNull();

    // Персональные данные не попадают в журнал (FR-NTF-232, FR-NTF-235).
    foreach ($logged as $line) {
        expect($line)->not->toContain('secret.sender@example.test');
    }
});

it('не отправляет событие контактов в поток заказов через адаптер транспорта', function (): void {
    // Поток контактов отличается от потока заказов при любых именах потоков.
    $router = new EventStreamRouter(
        orders: 'events.orders',
        notifications: 'events.notifications',
        contacts: 'events.contacts.test',
    );

    expect($router->streamFor(EventType::UserContactChanged))->toBe('events.contacts.test')
        ->and($router->streamFor(EventType::OrderDelivered))->toBe('events.orders');
});
