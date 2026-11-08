<?php

declare(strict_types=1);

use App\Logging\TechnicalLogger;
use App\Services\Events\Amqp\PhpAmqpBroker;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\EventStreamConsumer;
use App\Services\Events\RabbitMqEventStream;
use App\Services\Events\RabbitMqTopology;
use App\Services\Events\RedisEventStream;
use App\Services\Events\TransportDriver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Support\FakeAmqpBroker;

/*
|--------------------------------------------------------------------------
| Guard и изоляция сбоя транспорта (FR-NTF-545, FR-NTF-548…550, ЧТЗ 9.6)
|--------------------------------------------------------------------------
|
| Неизвестный транспорт — ошибка конфигурации. Недоступный брокер не роняет
| потребитель: проход возвращает degraded. Секреты подключения не попадают
| в журнал, а домен и сервисы уведомлений не знают про транспорт (arch-тест).
|
*/

it('останавливает процесс при неизвестном NOTIFICATIONS_TRANSPORT', function (): void {
    config(['notifications.transport.driver' => 'kafka']);
    app()->forgetInstance(EventStream::class);

    expect(fn (): EventStream => app(EventStream::class))->toThrow(InvalidArgumentException::class);

    config(['notifications.transport.driver' => 'redis']);
    app()->forgetInstance(EventStream::class);

    expect(app(EventStream::class))->toBeInstanceOf(RedisEventStream::class);
});

it('выбирает адаптер брокера при NOTIFICATIONS_TRANSPORT=rabbitmq', function (): void {
    config(['notifications.transport.driver' => TransportDriver::RABBITMQ]);
    app()->forgetInstance(EventStream::class);

    expect(app(EventStream::class))->toBeInstanceOf(RabbitMqEventStream::class);
});

it('сообщает degraded, когда Redis недоступен, и не падает', function (): void {
    bindFakeRedis([], new RuntimeException('connection refused'));
    app()->instance(EventStream::class, new RedisEventStream(''));

    $result = app(EventStreamConsumer::class)->consume('events.orders', 'consumer-1', 10, static fn (): int => 0);

    expect($result->degraded)->toBeTrue()
        ->and($result->processed)->toBe(0);
});

it('сообщает degraded, когда брокер недоступен, и не теряет процесс', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->available = false;

    $stream = new RabbitMqEventStream(
        $broker,
        new RabbitMqTopology([
            'exchange' => 'down.events',
            'dlx_exchange' => 'down.events.dlx',
            'dlq_queue' => 'down.dlq',
            'message_ttl_seconds' => 60,
            'max_attempts' => 3,
        ], ['events.orders'], ['order' => 'orders']),
        app(TechnicalLogger::class),
        0,
    );

    app()->instance(EventStream::class, $stream);

    $result = app(EventStreamConsumer::class)->consume('events.orders', 'consumer-1', 10, static fn (): int => 0);

    expect($result->degraded)->toBeTrue()
        ->and($stream->publish('events.orders', ['event_type' => 'order.delivered']))->toBeFalse();
});

it('не пишет секреты брокера в журнал при недоступности', function (): void {
    /** @var MockInterface&Log $log */
    $log = Log::spy();

    $broker = new PhpAmqpBroker([
        'host' => '127.0.0.1',
        'port' => 1,
        'user' => 'secret-user',
        'password' => 'super-secret-password',
        'vhost' => '/',
        'heartbeat' => 0,
        'timeout' => 1,
        'prefetch' => 1,
    ]);

    expect($broker->isConnected())->toBeFalse();

    $log->shouldHaveReceived('warning')->withArgs(
        static fn (string $message, array $context = []): bool => ! str_contains($message, 'super-secret-password')
            && ! str_contains((string) json_encode($context), 'super-secret-password')
            && ! str_contains((string) json_encode($context), 'secret-user'),
    )->atLeast()->once();
});

it('не содержит транспорта в домене и сервисах уведомлений (arch)', function (): void {
    $violations = [];

    foreach (['app/Domain', 'app/Services/Notifications'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            $contents = (string) $file->getContents();

            if (str_contains($contents, 'RabbitMq') || str_contains($contents, 'AmqpBroker')) {
                $violations[] = $file->getRelativePathname();
            }
        }
    }

    expect($violations)->toBe([]);
});

it('держит параметры брокера только в конфигурации', function (): void {
    $files = [];

    foreach (['app', 'routes', 'database'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            $contents = (string) $file->getContents();

            foreach (['RABBITMQ_HOST', 'RABBITMQ_PASSWORD', 'RABBITMQ_VHOST'] as $variable) {
                if (str_contains($contents, $variable)) {
                    $files[] = $file->getRelativePathname().':'.$variable;
                }
            }
        }
    }

    expect($files)->toBe([]);
});
