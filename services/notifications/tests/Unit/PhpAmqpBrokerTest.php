<?php

declare(strict_types=1);

use App\Services\Events\Amqp\PhpAmqpBroker;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/*
|--------------------------------------------------------------------------
| Клиент php-amqplib без сети (ЧТЗ 9.1, 9.5)
|--------------------------------------------------------------------------
|
| Канал подменяется моком AMQPChannel через фабрику в конструкторе:
| проверяются объявления, публикация, чтение, ack/reject, глубина очереди,
| разбор тела и заголовков, а также недоступность соединения. Реальный
| брокер и сокеты не используются.
|
*/

afterEach(function (): void {
    Mockery::close();
});

/**
 * Параметры подключения для проверок (сеть не используется).
 *
 * @return array{host: string, port: int, user: string, password: string, vhost: string,
 *               heartbeat: int, timeout: int, prefetch: int}
 */
function amqpBrokerConfig(): array
{
    return [
        'host' => '127.0.0.1',
        'port' => 1,
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'heartbeat' => 0,
        'timeout' => 1,
        'prefetch' => 10,
    ];
}

/**
 * Брокер с подменённым каналом.
 */
function amqpBrokerWith(object $channel): PhpAmqpBroker
{
    return new PhpAmqpBroker(amqpBrokerConfig(), static function () use ($channel): ?AMQPChannel {
        return $channel instanceof AMQPChannel ? $channel : null;
    });
}

/**
 * Сообщение очереди с телом и заголовками.
 *
 * @param mixed $body тело сообщения
 * @param array<string, mixed> $headers служебные заголовки
 */
function amqpBrokerMessage(mixed $body, array $headers = []): AMQPMessage
{
    $properties = ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT];

    if ($headers !== []) {
        $properties['application_headers'] = new AMQPTable($headers);
    }

    $message = new AMQPMessage((string) $body, $properties);
    $message->setDeliveryTag(1);
    $message->setDeliveryInfo(1, false, 'events', 'orders.order.delivered');

    return $message;
}

it('сообщает недоступность, когда канал не создаётся', function (): void {
    $broker = new PhpAmqpBroker(amqpBrokerConfig(), static fn (): ?AMQPChannel => null);

    expect($broker->ensureExchange('events'))->toBeFalse()
        ->and($broker->ensureQueue('q'))->toBeFalse()
        ->and($broker->bind('events', 'q', '#'))->toBeFalse()
        ->and($broker->publish('events', 'key', [], 1000, []))->toBeFalse()
        ->and($broker->read('q', 10))->toBeNull()
        ->and($broker->ack('1'))->toBeFalse()
        ->and($broker->reject('1', false))->toBeFalse()
        ->and($broker->depth('q'))->toBeNull()
        ->and($broker->isConnected())->toBeFalse();
});

it('превращает ошибку фабрики канала в недоступность', function (): void {
    $broker = new PhpAmqpBroker(amqpBrokerConfig(), static function (): ?AMQPChannel {
        throw new RuntimeException('connect failed');
    });

    expect($broker->ensureExchange('events'))->toBeFalse();
});

it('объявляет exchange, очередь с аргументами и привязку', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('exchange_declare')->once()->with('events', 'topic', false, true, false);
    $channel->shouldReceive('queue_declare')
        ->once()
        ->with('q', false, true, false, false, false, Mockery::on(
            static fn (mixed $table): bool => $table instanceof AMQPTable && $table->getNativeData() === ['x-message-ttl' => 1000],
        ));
    $channel->shouldReceive('queue_bind')->once()->with('q', 'events', 'orders.#');

    $broker = amqpBrokerWith($channel);

    expect($broker->ensureExchange('events'))->toBeTrue()
        ->and($broker->ensureQueue('q', ['x-message-ttl' => 1000]))->toBeTrue()
        ->and($broker->bind('events', 'q', 'orders.#'))->toBeTrue();
});

it('превращает ошибку операции в недоступность и закрывает канал', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('exchange_declare')->once()->andThrow(new RuntimeException('channel closed'));
    $channel->shouldReceive('close')->zeroOrMoreTimes();

    expect(amqpBrokerWith($channel)->ensureExchange('events'))->toBeFalse();
});

it('публикует JSON-сообщение с TTL и заголовками', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_publish')
        ->once()
        ->with(Mockery::type(AMQPMessage::class), 'events', 'orders.order.delivered', true)
        ->andReturnUsing(static function (AMQPMessage $message): null {
            expect(json_decode($message->getBody(), true))->toBe(['event_type' => 'order.delivered'])
                ->and($message->get('expiration'))->toBe('60000')
                ->and($message->get('application_headers'))->toBeInstanceOf(AMQPTable::class);

            return null;
        });
    $channel->shouldReceive('wait_for_pending_acks_returns')->once()->with(1.0);

    expect(amqpBrokerWith($channel)->publish(
        'events',
        'orders.order.delivered',
        ['event_type' => 'order.delivered'],
        60000,
        ['x-attempt' => 0],
    ))->toBeTrue();
});

it('сообщает об отказе публикации и закрывает канал', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_publish')->once()->andThrow(new RuntimeException('nack'));
    $channel->shouldReceive('close')->zeroOrMoreTimes();

    expect(amqpBrokerWith($channel)->publish('events', 'key', [], 1000, []))->toBeFalse();
});

it('не читает при неположительном лимите', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);

    expect(amqpBrokerWith($channel)->read('q', 0))->toBe([]);
});

it('читает сообщения с полями и заголовками', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_get')
        ->with('q', false)
        ->andReturn(
            amqpBrokerMessage(json_encode(['event_type' => 'order.delivered', 'nested' => ['x']]), ['x-attempt' => 2]),
            null,
        );

    $messages = amqpBrokerWith($channel)->read('q', 10) ?? [];

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->fields)->toBe(['event_type' => 'order.delivered', 'nested' => ''])
        ->and($messages[0]->headers)->toBe(['x-attempt' => 2]);
});

it('возвращает пустые поля для невалидного тела и без заголовков', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_get')
        ->andReturn(amqpBrokerMessage('{bad json'), null);

    $messages = amqpBrokerWith($channel)->read('q', 10) ?? [];

    expect($messages[0]->fields)->toBe([])
        ->and($messages[0]->headers)->toBe([]);
});

it('возвращает пустые поля для тела не-объекта', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_get')
        ->andReturn(amqpBrokerMessage('5'), null);

    $messages = amqpBrokerWith($channel)->read('q', 10) ?? [];

    expect($messages[0]->fields)->toBe([]);
});

it('возвращает null при отказе чтения', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_get')->once()->andThrow(new RuntimeException('read failed'));

    expect(amqpBrokerWith($channel)->read('q', 10))->toBeNull();
});

it('подтверждает и отклоняет прочитанное сообщение', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_get')
        ->andReturn(
            amqpBrokerMessage(json_encode(['event_type' => 'order.delivered'])),
            amqpBrokerMessage(json_encode(['event_type' => 'order.delivered'])),
            null,
        );
    $channel->shouldReceive('basic_ack')->once()->with(1);
    $channel->shouldReceive('basic_reject')->once()->with(1, false);

    $broker = amqpBrokerWith($channel);
    $broker->read('q', 1);

    expect($broker->ack('1'))->toBeTrue();

    $broker->read('q', 1);

    expect($broker->reject('1', false))->toBeTrue()
        ->and($broker->ack('unknown'))->toBeFalse()
        ->and($broker->reject('unknown', false))->toBeFalse();
});

it('превращает ошибки ack и reject в false', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('basic_get')
        ->andReturn(
            amqpBrokerMessage(json_encode(['event_type' => 'order.delivered'])),
            amqpBrokerMessage(json_encode(['event_type' => 'order.delivered'])),
            null,
        );
    $channel->shouldReceive('basic_ack')->once()->andThrow(new RuntimeException('ack failed'));
    $channel->shouldReceive('basic_reject')->once()->andThrow(new RuntimeException('reject failed'));

    $broker = amqpBrokerWith($channel);
    $broker->read('q', 1);

    expect($broker->ack('1'))->toBeFalse();

    $broker->read('q', 1);

    expect($broker->reject('1', false))->toBeFalse();
});

it('переживает ошибку закрытия канала', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('close')->once()->andThrow(new RuntimeException('close failed'));

    $broker = amqpBrokerWith($channel);

    expect($broker->isConnected())->toBeTrue();

    $broker->close();
});

it('читает глубину очереди и обрабатывает некорректный ответ', function (): void {
    $channel = Mockery::mock(AMQPChannel::class);
    $channel->shouldReceive('queue_declare')
        ->once()
        ->with('q', true, true, false, false)
        ->andReturn(['q', 7, 0]);
    $channel->shouldReceive('queue_declare')
        ->once()
        ->with('broken', true, true, false, false)
        ->andReturn('bad');
    $channel->shouldReceive('queue_declare')
        ->once()
        ->with('fail', true, true, false, false)
        ->andThrow(new RuntimeException('declare failed'));

    $broker = amqpBrokerWith($channel);

    expect($broker->depth('q'))->toBe(7)
        ->and($broker->depth('broken'))->toBeNull()
        ->and($broker->depth('fail'))->toBeNull()
        ->and($broker->isConnected())->toBeTrue();
});
