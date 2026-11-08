<?php

declare(strict_types=1);

use App\Logging\TechnicalLogger;
use App\Services\Events\Amqp\BrokerMessage;
use App\Services\Events\RabbitMqEventStream;
use App\Services\Events\RabbitMqTopology;
use Tests\Support\FakeAmqpBroker;

/*
|--------------------------------------------------------------------------
| Адаптер RabbitMQ сервиса без сети (ЧТЗ 9.1, 9.5)
|--------------------------------------------------------------------------
|
| Порт AmqpBroker подменяется FakeAmqpBroker: проверяются публикация,
| потребление, ack/reclaim, возврат DLQ, границы и ошибки. Реальный
| брокер не нужен; паритет с Redis проверяет отдельный TransportParityTest.
|
*/

/**
 * Топология для проверок адаптера.
 */
function rabbitAdapterTopology(int $maxAttempts = 3): RabbitMqTopology
{
    return new RabbitMqTopology([
        'exchange' => 'test.events',
        'dlx_exchange' => 'test.events.dlx',
        'dlq_queue' => 'test.dlq',
        'message_ttl_seconds' => 60,
        'max_attempts' => $maxAttempts,
    ], ['events.orders', 'events.notifications', 'events.contacts'], [
        'order' => 'orders',
        'courier' => 'orders',
        'user' => 'contacts',
        'notification' => 'notifications',
    ]);
}

/**
 * Адаптер поверх подставного брокера.
 */
function rabbitAdapter(FakeAmqpBroker $broker, int $maxAttempts = 3, int $idle = 0): RabbitMqEventStream
{
    return new RabbitMqEventStream(
        $broker,
        rabbitAdapterTopology($maxAttempts),
        app(TechnicalLogger::class),
        $idle,
    );
}

/**
 * Сообщение очереди с заданными полями.
 *
 * @param array<string, string> $fields
 * @param array<string, mixed> $headers
 */
function rabbitBrokerMessage(string $id, array $fields, array $headers = [], string $routingKey = ''): BrokerMessage
{
    return new BrokerMessage($id, $fields, $headers, $routingKey);
}

/**
 * Поля конверта события заказа.
 *
 * @return array<string, string>
 */
function rabbitOrderFields(string $eventType = 'order.delivered'): array
{
    return ['event_type' => $eventType, 'order_id' => '42', 'message_id' => 'm-42'];
}

it('не создаётся с отрицательным порогом повторной выдачи', function (): void {
    expect(fn (): RabbitMqEventStream => new RabbitMqEventStream(
        new FakeAmqpBroker,
        rabbitAdapterTopology(),
        app(TechnicalLogger::class),
        -1,
    ))->toThrow(InvalidArgumentException::class);
});

it('проверяет границы пачки при чтении и захвате', function (): void {
    $stream = rabbitAdapter(new FakeAmqpBroker);

    expect(fn (): ?array => $stream->read('events.notifications', 'g', 'c', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ?array => $stream->reclaim('events.notifications', 'g', 'c', 0, 0))->toThrow(InvalidArgumentException::class);
});

it('объявляет группу идемпотентно и переиспользует объявленную очередь', function (): void {
    $broker = new FakeAmqpBroker;
    $stream = rabbitAdapter($broker);

    expect($stream->ensureGroup('events.notifications', 'g'))->toBeTrue();

    $declared = count($broker->bindings);

    // Повторный вызов не объявляет топологию заново.
    expect($stream->ensureGroup('events.notifications', 'g'))->toBeTrue()
        ->and(count($broker->bindings))->toBe($declared);
});

it('возвращает null, когда брокер недоступен при объявлении', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->available = false;

    expect(rabbitAdapter($broker)->read('events.notifications', 'g', 'c', 10))->toBeNull();
});

it('возвращает null, когда чтение очереди недоступно', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->readUnavailable = true;

    expect(rabbitAdapter($broker)->read('events.notifications', 'g', 'c', 10))->toBeNull();
});

it('читает сообщения и отдаёт их в контракте потока', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.notifications.g'] = [
        rabbitBrokerMessage('m1', rabbitOrderFields(), routingKey: 'orders.order.delivered'),
    ];
    $stream = rabbitAdapter($broker);

    $messages = $stream->read('events.notifications', 'g', 'c', 10) ?? [];

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->id)->toBe('delivery-m1')
        ->and($messages[0]->field('event_type'))->toBe('order.delivered');
});

it('возвращает ожидающие сообщения и учитывает порог и лимит захвата', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.notifications.g'] = [
        rabbitBrokerMessage('m1', rabbitOrderFields()),
        rabbitBrokerMessage('m2', rabbitOrderFields()),
        rabbitBrokerMessage('m3', rabbitOrderFields()),
    ];
    $stream = rabbitAdapter($broker);
    $stream->read('events.notifications', 'g', 'c', 10);

    // Порог не достигнут: сообщения ещё не «зависли».
    expect($stream->reclaim('events.notifications', 'g', 'c', 3600, 10))->toBe([]);

    // Лимит захвата соблюдается.
    $claimed = $stream->reclaim('events.notifications', 'g', 'c', 0, 1) ?? [];

    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]->id)->toBe('delivery-m1');
});

it('пропускает при захвате сообщения другого потока', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.contacts.cg'] = [rabbitBrokerMessage('c1', rabbitOrderFields())];
    $broker->queues['events.notifications.g'] = [rabbitBrokerMessage('m1', rabbitOrderFields())];
    $stream = rabbitAdapter($broker);

    $stream->read('events.contacts', 'cg', 'c', 10);

    expect($stream->reclaim('events.notifications', 'g', 'c', 0, 10))->toBe([]);
});

it('возвращает null при захвате, когда брокер отключён', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->available = false;

    expect(rabbitAdapter($broker)->reclaim('events.notifications', 'g', 'c', 0, 10))->toBeNull();
});

it('подтверждает сообщение и сообщает об отказе подтверждения', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.notifications.g'] = [rabbitBrokerMessage('m1', rabbitOrderFields())];
    $stream = rabbitAdapter($broker);
    $stream->read('events.notifications', 'g', 'c', 10);

    expect($stream->acknowledge('events.notifications', 'g', 'delivery-m1'))->toBeTrue()
        ->and($broker->acknowledged)->toContain('delivery-m1');

    $broker->available = false;

    expect($stream->acknowledge('events.notifications', 'g', 'missing'))->toBeFalse();
});

it('не публикует событие без домена маршрутизации', function (): void {
    $broker = new FakeAmqpBroker;

    expect(rabbitAdapter($broker)->publish('events.notifications', ['event_type' => 'mystery.event']))->toBeFalse()
        ->and($broker->published)->toBe([]);
});

it('не публикует событие, когда exchange недоступен', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->available = false;

    expect(rabbitAdapter($broker)->publish('events.notifications', rabbitOrderFields()))->toBeFalse();
});

it('публикует событие с ключом маршрутизации и TTL', function (): void {
    $broker = new FakeAmqpBroker;
    $stream = rabbitAdapter($broker);

    expect($stream->publish('events.notifications', rabbitOrderFields()))->toBeTrue()
        ->and($broker->published[0]['routing_key'])->toBe('orders.order.delivered')
        ->and($stream->deadLetterQueue())->toBe('test.dlq');
});

it('проверяет границы возврата DLQ и недоступность', function (): void {
    $stream = rabbitAdapter(new FakeAmqpBroker);

    expect($stream->redriveDeadLetters(0))->toBe(0);

    $down = new FakeAmqpBroker;
    $down->available = false;
    expect(rabbitAdapter($down)->redriveDeadLetters(10))->toBeNull();

    $noRead = new FakeAmqpBroker;
    $noRead->readUnavailable = true;
    expect(rabbitAdapter($noRead)->redriveDeadLetters(10))->toBeNull();
});

it('оставляет сообщение в DLQ после предела возвратов', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['test.dlq'] = [
        rabbitBrokerMessage('d1', rabbitOrderFields(), ['x-redriven' => 3]),
    ];
    $stream = rabbitAdapter($broker, maxAttempts: 3);

    expect($stream->redriveDeadLetters(10))->toBe(0)
        ->and($broker->acknowledged)->toBe([]);
});

it('возвращает сообщение в DLQ, если публикация не удалась', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->failUnroutable = true;
    $broker->queues['test.dlq'] = [rabbitBrokerMessage('d1', rabbitOrderFields())];
    $stream = rabbitAdapter($broker);

    expect($stream->redriveDeadLetters(10))->toBe(0)
        ->and($broker->acknowledged)->toBe([]);
});

it('возвращает сообщения DLQ в основной поток и подтверждает их', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->bindings[] = ['exchange' => 'test.events', 'queue' => 'events.notifications.g', 'pattern' => 'orders.#'];
    $broker->queues['test.dlq'] = [rabbitBrokerMessage('d1', rabbitOrderFields())];
    $stream = rabbitAdapter($broker);

    expect($stream->redriveDeadLetters(10))->toBe(1)
        ->and($broker->acknowledged)->toContain('delivery-d1')
        ->and($broker->published[0]['headers']['x-redriven'])->toBe(1);
});

it('отправляет в DLX сообщение, исчерпавшее попытки при следующем чтении', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.notifications.g'] = [
        rabbitBrokerMessage('m1', rabbitOrderFields(), ['x-attempt' => 2]),
    ];
    $stream = rabbitAdapter($broker, maxAttempts: 3);
    $stream->read('events.notifications', 'g', 'c', 10);
    $broker->queues['events.notifications.g'] = [];

    $stream->read('events.notifications', 'g', 'c', 10);

    expect($broker->rejected)->toContain('delivery-m1');
});

it('повторяет неподтверждённое сообщение с увеличенной попыткой', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.notifications.g'] = [
        rabbitBrokerMessage('m1', rabbitOrderFields(), ['x-attempt' => 0], 'orders.order.delivered'),
    ];
    $stream = rabbitAdapter($broker, maxAttempts: 5);
    $stream->read('events.notifications', 'g', 'c', 10);

    $stream->read('events.notifications', 'g', 'c', 10);

    expect($broker->published)->toHaveCount(1)
        ->and($broker->published[0]['headers']['x-attempt'])->toBe(1)
        ->and($broker->acknowledged)->toContain('delivery-m1');
});

it('не трогает при повторе сообщения другого потока', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.contacts.cg'] = [rabbitBrokerMessage('c1', rabbitOrderFields())];
    $broker->queues['events.notifications.g'] = [rabbitBrokerMessage('m1', rabbitOrderFields())];
    $stream = rabbitAdapter($broker);

    $stream->read('events.contacts', 'cg', 'c', 10);
    $stream->read('events.notifications', 'g', 'c', 10);

    expect($broker->acknowledged)->toBe([])
        ->and($broker->rejected)->toBe([]);
});

it('оставляет сообщение неподтверждённым, если повторная публикация не удалась', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->queues['events.notifications.g'] = [
        rabbitBrokerMessage('m1', rabbitOrderFields(), ['x-attempt' => 0], 'orders.order.delivered'),
    ];
    $stream = rabbitAdapter($broker, maxAttempts: 5);
    $stream->read('events.notifications', 'g', 'c', 10);
    $broker->available = false;

    $stream->read('events.notifications', 'g', 'c', 10);

    expect($broker->acknowledged)->toBe([]);
});
