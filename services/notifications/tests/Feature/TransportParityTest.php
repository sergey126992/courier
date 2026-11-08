<?php

declare(strict_types=1);

use App\Logging\TechnicalLogger;
use App\Services\Events\Amqp\PhpAmqpBroker;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\RabbitMqEventStream;
use App\Services\Events\RabbitMqTopology;
use App\Services\Events\RedisEventStream;
use App\Services\Events\TransportDriver;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Паритет транспортов сервиса (FR-NTF-547, ЧТЗ 9.4)
|--------------------------------------------------------------------------
|
| Один набор кейсов выполняется для Redis Streams и RabbitMQ: публикация →
| потребление → ack → reclaim → повтор → DLX/DLQ → деградация. Поведение
| адаптеров совпадает; расхождение — падение теста, а не «известное
| ограничение». RabbitMQ прогоняется при доступном брокере (профиль
| `broker`), Redis — всегда. Имена потоков и очередей уникальны для прогона.
|
*/

/**
 * Транспорты паритетного набора.
 *
 * @return list<string>
 */
function parityTransports(): array
{
    return [TransportDriver::REDIS, TransportDriver::RABBITMQ];
}

/**
 * Конфигурация брокера для проверки: уникальные имена, локальный хост.
 *
 * @return array{host: string, port: int, user: string, password: string, vhost: string,
 *               heartbeat: int, timeout: int, prefetch: int, exchange: string,
 *               dlx_exchange: string, dlq_queue: string, message_ttl_seconds: int, max_attempts: int}
 */
function parityRabbitConfig(int $maxAttempts, string $suffix): array
{
    return [
        'host' => (string) config('notifications.transport.rabbitmq.host'),
        'port' => (int) config('notifications.transport.rabbitmq.port'),
        'user' => (string) config('notifications.transport.rabbitmq.user'),
        'password' => (string) config('notifications.transport.rabbitmq.password'),
        'vhost' => (string) config('notifications.transport.rabbitmq.vhost'),
        'heartbeat' => (int) config('notifications.transport.rabbitmq.heartbeat'),
        'timeout' => 2,
        'prefetch' => 10,
        'exchange' => 'parity.events.'.$suffix,
        'dlx_exchange' => 'parity.events.dlx.'.$suffix,
        'dlq_queue' => 'parity.notifications.dlq.'.$suffix,
        'message_ttl_seconds' => 60,
        'max_attempts' => $maxAttempts,
    ];
}

/**
 * Пара «адаптер и его координаты» для паритетного кейса.
 *
 * @return array{stream: EventStream, name: string, group: string, suffix: string,
 *               dlx: bool, broker: PhpAmqpBroker|null, key: string|null}
 */
function paritySubject(string $transport, string $name = 'events.orders', int $maxAttempts = 5): array
{
    $suffix = Str::lower(Str::random(8));
    $group = 'parity-'.$suffix;

    if ($transport === TransportDriver::REDIS) {
        return [
            'stream' => new RedisEventStream('parity.'.$suffix.':'),
            'name' => $name,
            'group' => $group,
            'suffix' => $suffix,
            'dlx' => false,
            'broker' => null,
            'key' => 'parity.'.$suffix.':'.$name,
        ];
    }

    $config = parityRabbitConfig($maxAttempts, $suffix);
    $broker = new PhpAmqpBroker([
        'host' => (string) $config['host'],
        'port' => (int) $config['port'],
        'user' => (string) $config['user'],
        'password' => (string) $config['password'],
        'vhost' => (string) $config['vhost'],
        'heartbeat' => (int) $config['heartbeat'],
        'timeout' => (int) $config['timeout'],
        'prefetch' => (int) $config['prefetch'],
    ]);

    $topology = new RabbitMqTopology($config, ['events.orders', 'events.notifications', 'events.contacts'], [
        'order' => 'orders',
        'courier' => 'orders',
        'user' => 'contacts',
        'notification' => 'notifications',
    ]);

    return [
        'stream' => new RabbitMqEventStream($broker, $topology, app(TechnicalLogger::class), 0),
        'name' => $name,
        'group' => $group,
        'suffix' => $suffix,
        'dlx' => true,
        'broker' => $broker,
        'key' => null,
    ];
}

/**
 * Доступен ли транспорт кейса: RabbitMQ требует профиля `broker`.
 *
 * @param array<string, mixed> $subject
 */
function parityBrokerAvailable(string $transport, array $subject): bool
{
    return $transport !== TransportDriver::RABBITMQ
        || $subject['stream']->ensureGroup((string) $subject['name'], (string) $subject['group']);
}

/**
 * Убирает поток Redis после кейса: тест не оставляет данных другим прогонам.
 */
/**
 * @param array<string, mixed> $subject
 */
function parityCleanup(array $subject): void
{
    if ($subject['broker'] !== null) {
        $subject['broker']->close();

        return;
    }

    Redis::connection()->command('executeRaw', [['DEL', 'parity.'.$subject['suffix'].':'.$subject['name']]]);
}

/**
 * Запоминает предмет кейса для очистки после теста.
 *
 * @param array<string, mixed> $subject
 * @return array<string, mixed>
 */
function parityTrack(array $subject): array
{
    $GLOBALS['paritySubjects'][] = $subject;

    return $subject;
}

afterEach(function (): void {
    // Подметаем уникальные потоки после каждого кейса.
    /** @var list<array<string, mixed>> $subjects */
    $subjects = $GLOBALS['paritySubjects'] ?? [];

    foreach ($subjects as $subject) {
        parityCleanup($subject);
    }

    $GLOBALS['paritySubjects'] = [];
});

it('публикует, потребляет и подтверждает сообщение', function (string $transport): void {
    $subject = parityTrack(paritySubject($transport, 'events.notifications'));

    if (! parityBrokerAvailable($transport, $subject)) {
        $this->markTestSkipped('Брокер RabbitMQ недоступен: запустите docker compose --profile broker.');
    }

    // Consumer group создаётся до публикации: так работает потребитель сервиса.
    expect($subject['stream']->ensureGroup($subject['name'], $subject['group']))->toBeTrue();

    $fields = notificationEventFields('notification.sent', ['order_id' => 42]);

    expect($subject['stream']->publish($subject['name'], $fields))->toBeTrue();

    $messages = $subject['stream']->read($subject['name'], $subject['group'], 'consumer-1', 10);
    $first = $messages[0] ?? null;

    expect($messages)->not->toBeNull()
        ->and($messages)->toHaveCount(1)
        ->and($first?->field('event_type'))->toBe('notification.sent')
        ->and($subject['stream']->acknowledge($subject['name'], $subject['group'], $first === null ? '' : $first->id))->toBeTrue();

    expect($subject['stream']->read($subject['name'], $subject['group'], 'consumer-1', 10))->toBe([]);
    expect($subject['stream']->reclaim($subject['name'], $subject['group'], 'consumer-1', 0, 10))->toBe([]);
})->with(parityTransports());

it('возвращает неподтверждённое сообщение при reclaim', function (string $transport): void {
    $subject = parityTrack(paritySubject($transport, 'events.notifications'));

    if (! parityBrokerAvailable($transport, $subject)) {
        $this->markTestSkipped('Брокер RabbitMQ недоступен: запустите docker compose --profile broker.');
    }

    // Consumer group создаётся до публикации: так работает потребитель сервиса.
    expect($subject['stream']->ensureGroup($subject['name'], $subject['group']))->toBeTrue();

    $fields = notificationEventFields('notification.sent');
    expect($subject['stream']->publish($subject['name'], $fields))->toBeTrue();

    $messages = $subject['stream']->read($subject['name'], $subject['group'], 'consumer-1', 10);
    expect($messages)->not->toBeNull()->and($messages)->toHaveCount(1);

    $reclaimed = $subject['stream']->reclaim($subject['name'], $subject['group'], 'consumer-1', 0, 10);
    $delivered = $messages[0] ?? null;
    $again = $reclaimed[0] ?? null;

    expect($reclaimed)->not->toBeNull()
        ->and($reclaimed)->toHaveCount(1)
        ->and($again?->field('message_id'))->toBe($delivered?->field('message_id'));

    expect($subject['stream']->acknowledge($subject['name'], $subject['group'], $again === null ? '' : $again->id))->toBeTrue();
})->with(parityTransports());

it('не теряет сообщение после предела попыток (DLX/DLQ или pending)', function (string $transport): void {
    $subject = parityTrack(paritySubject($transport, 'events.notifications', maxAttempts: 1));

    if (! parityBrokerAvailable($transport, $subject)) {
        $this->markTestSkipped('Брокер RabbitMQ недоступен: запустите docker compose --profile broker.');
    }

    // Consumer group создаётся до публикации: так работает потребитель сервиса.
    expect($subject['stream']->ensureGroup($subject['name'], $subject['group']))->toBeTrue();

    $fields = notificationEventFields('notification.failed');
    expect($subject['stream']->publish($subject['name'], $fields))->toBeTrue();

    $messages = $subject['stream']->read($subject['name'], $subject['group'], 'consumer-1', 10);
    expect($messages)->not->toBeNull()->and($messages)->toHaveCount(1);

    if (! $subject['dlx']) {
        // Redis не имеет DLX: сообщение остаётся в pending и выдаётся повторно.
        $reclaimed = $subject['stream']->reclaim($subject['name'], $subject['group'], 'consumer-1', 0, 10);
        expect($reclaimed)->not->toBeNull()->and($reclaimed)->toHaveCount(1);

        return;
    }

    // Следующий проход фиксирует отсутствие ack и отправляет сообщение в DLX.
    $subject['stream']->read($subject['name'], $subject['group'], 'consumer-1', 10);

    /** @var RabbitMqEventStream $stream */
    $stream = $subject['stream'];
    /** @var PhpAmqpBroker $broker */
    $broker = $subject['broker'];

    expect($broker->depth($stream->deadLetterQueue()))->toBe(1);

    // Сообщение DLQ возвращается в основной поток и снова доступно потребителю.
    expect($stream->redriveDeadLetters(10))->toBe(1);

    $redriven = $stream->read($subject['name'], $subject['group'], 'consumer-1', 10);
    expect($redriven)->not->toBeNull()->and($redriven)->toHaveCount(1);
})->with(parityTransports());

it('сообщает о недоступности транспорта без исключения', function (string $transport): void {
    if ($transport === TransportDriver::REDIS) {
        bindFakeRedis([], new RuntimeException('connection refused'));
        $stream = new RedisEventStream('');
    } else {
        $config = parityRabbitConfig(1, 'down'.Str::lower(Str::random(4)));
        $config['host'] = '127.0.0.1';
        $config['port'] = 1;
        $stream = new RabbitMqEventStream(
            new PhpAmqpBroker([
                'host' => (string) $config['host'],
                'port' => (int) $config['port'],
                'user' => (string) $config['user'],
                'password' => (string) $config['password'],
                'vhost' => (string) $config['vhost'],
                'heartbeat' => 0,
                'timeout' => 1,
                'prefetch' => 1,
            ]),
            new RabbitMqTopology($config, ['events.orders.down'], ['order' => 'orders']),
            app(TechnicalLogger::class),
            0,
        );
    }

    expect($stream->ensureGroup('events.orders.down', 'g'))->toBeFalse()
        ->and($stream->read('events.orders.down', 'g', 'c', 10))->toBeNull()
        ->and($stream->publish('events.orders.down', notificationEventFields('order.delivered')))->toBeFalse();
})->with(parityTransports());
