<?php

declare(strict_types=1);

use App\Services\Events\Contracts\EventStream;
use App\Services\Events\StreamMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\FakeEventStream;
use Tests\Support\FakeRedisConnection;
use Tests\Support\FakeRedisManager;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Подменяет Redis подставным подключением: проверки состояния сервиса
 * не ходят в сеть (ЧТЗ 9.7).
 *
 * @param array<string, mixed> $responses ответы команд по имени
 */
function bindFakeRedis(array $responses = [], ?Throwable $failure = null): void
{
    app()->instance('redis', new FakeRedisManager(new FakeRedisConnection($responses, $failure)));
    Redis::clearResolvedInstances();
}

/**
 * Ожидает отказа базы на уровне схемы.
 *
 * Операция выполняется во вложенной транзакции (savepoint): после ошибки
 * проверки уникальности и CHECK транзакция теста остаётся работоспособной,
 * поэтому дальнейшие утверждения возможны (ЧТЗ 9.2, п. 2).
 *
 * @param Closure(): mixed $operation
 */
function expectDatabaseRejection(Closure $operation): void
{
    expect(fn (): mixed => DB::transaction(static fn (): mixed => $operation()))->toThrow(QueryException::class);
}

/**
 * Подменяет транспорт событий подставным потоком: проверки потребителя
 * не ходят в Redis (ЧТЗ 9.7).
 *
 * @param list<StreamMessage> $messages новые сообщения потока
 * @param list<StreamMessage> $pending сообщения, ожидающие повторной выдачи
 */
function fakeEventStream(array $messages = [], array $pending = []): FakeEventStream
{
    $stream = new FakeEventStream($messages, $pending);

    app()->instance(EventStream::class, $stream);

    return $stream;
}

/**
 * Поля сообщения потока для события заказа (ЧТЗ 8.3, 8.4).
 *
 * @param array<string, mixed> $payload дополнительные или переопределяющие поля payload
 * @return array<string, string>
 */
function notificationEventFields(string $eventType = 'order.delivered', array $payload = [], ?string $messageId = null): array
{
    $messageId ??= (string) Str::uuid();

    $base = [
        'event_id' => $messageId,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
        'sender_id' => 7,
        'status' => match ($eventType) {
            'order.created' => 'created',
            'order.assigned' => 'assigned',
            'order.picked_up' => 'picked_up',
            'order.delivered' => 'delivered',
            'order.failed' => 'failed',
            'order.cancelled' => 'cancelled',
            default => 'delivered',
        },
        'occurred_at' => '2026-11-06T10:00:00+00:00',
        'locale' => 'ru',
    ];

    $payload = array_merge($base, $payload);

    return [
        'message_id' => $messageId,
        'event_type' => $eventType,
        'schema_version' => 'v1',
        'correlation_id' => 'corr-00000001',
        'occurred_at' => '2026-11-06T10:00:00+00:00',
        'aggregate_type' => 'order',
        'aggregate_id' => (string) $payload['order_id'],
        'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
}

/**
 * Сообщение потока с полями события заказа.
 *
 * @param array<string, mixed> $payload дополнительные или переопределяющие поля payload
 */
function notificationStreamMessage(
    string $eventType = 'order.delivered',
    array $payload = [],
    ?string $messageId = null,
    string $streamId = '0-1',
): StreamMessage {
    return new StreamMessage($streamId, notificationEventFields($eventType, $payload, $messageId));
}

/**
 * Поля сообщения потока контактов (FR-NTF-231, схема user-contact-changed.v1).
 *
 * @return array<string, string>
 */
function contactEventFields(
    int $userId = 7,
    string $email = 'sender@example.test',
    string $locale = 'ru',
    ?string $messageId = null,
    ?string $eventId = null,
): array {
    $messageId ??= (string) Str::uuid();
    $eventId ??= $messageId;

    return [
        'message_id' => $messageId,
        'event_type' => 'user.contact_changed',
        'schema_version' => 'v1',
        'correlation_id' => 'corr-00000002',
        'occurred_at' => '2026-11-06T10:00:00+00:00',
        'aggregate_type' => 'user',
        'aggregate_id' => (string) $userId,
        'payload' => (string) json_encode([
            'event_id' => $eventId,
            'user_id' => $userId,
            'email' => $email,
            'locale' => $locale,
            'occurred_at' => '2026-11-06T10:00:00+00:00',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
}

/**
 * Сообщение потока контактов.
 */
function contactStreamMessage(
    int $userId = 7,
    string $email = 'sender@example.test',
    string $locale = 'ru',
    ?string $messageId = null,
    string $streamId = '0-1',
    ?string $eventId = null,
): StreamMessage {
    return new StreamMessage($streamId, contactEventFields($userId, $email, $locale, $messageId, $eventId));
}
