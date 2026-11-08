<?php

declare(strict_types=1);

use App\Logging\TechnicalLogger;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\RabbitMqEventStream;
use App\Services\Events\RabbitMqTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeAmqpBroker;

/*
|--------------------------------------------------------------------------
| Ветки команды потребителя (ЧТЗ 8.6, 9.2)
|--------------------------------------------------------------------------
|
| Проверяются некорректная конфигурация, явный поток, режим возврата DLQ
| и недоступная DLQ. Долгоживущий цикл и обработчики сигналов требуют
| отдельного процесса и здесь не запускаются: одиночный проход покрывает
| ту же логику чтения и вывода.
|
*/

uses(RefreshDatabase::class);

/**
 * Адаптер RabbitMQ поверх подставного брокера для режима возврата DLQ.
 */
function consumeRabbitStream(FakeAmqpBroker $broker): RabbitMqEventStream
{
    return new RabbitMqEventStream(
        $broker,
        new RabbitMqTopology([
            'exchange' => 'test.events',
            'dlx_exchange' => 'test.events.dlx',
            'dlq_queue' => 'test.dlq',
            'message_ttl_seconds' => 60,
            'max_attempts' => 3,
        ], ['events.orders', 'events.notifications', 'events.contacts'], [
            'order' => 'orders',
            'courier' => 'orders',
            'user' => 'contacts',
            'notification' => 'notifications',
        ]),
        app(TechnicalLogger::class),
        0,
    );
}

it('возвращает 2 при некорректной конфигурации потоков', function (): void {
    config(['notifications.transport.streams.orders' => '']);

    $this->artisan('notifications:consume', ['--once' => true])
        ->expectsOutputToContain('некорректная конфигурация')
        ->assertExitCode(2);
});

it('возвращает 2 при некорректном --limit', function (): void {
    $this->artisan('notifications:consume', ['--once' => true, '--limit' => '0'])
        ->expectsOutputToContain('некорректная конфигурация')
        ->assertExitCode(2);
});

it('возвращает 2 на неизвестный явный поток', function (): void {
    fakeEventStream();

    $this->artisan('notifications:consume', ['--once' => true, '--stream' => 'events.unknown'])
        ->expectsOutputToContain('некорректная конфигурация')
        ->assertExitCode(2);
});

it('обрабатывает явно указанный поток контактов', function (): void {
    fakeEventStream();

    expect(Artisan::call('notifications:consume', ['--once' => true, '--stream' => 'events.contacts', '--json' => true]))
        ->toBe(0);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['stream'])->toBe('events.contacts')
        ->and($payload['read'])->toBe(0);
});

it('возвращает сообщения DLQ в основном цикле возврата', function (): void {
    config(['notifications.transport.rabbitmq.dlq_queue' => 'test.dlq']);
    $broker = new FakeAmqpBroker;
    app()->instance(EventStream::class, consumeRabbitStream($broker));

    $this->artisan('notifications:consume', ['--once' => true, '--stream' => 'test.dlq'])
        ->expectsOutputToContain('из DLQ возвращено 0 сообщений')
        ->assertExitCode(0);
});

it('возвращает 1, когда DLQ недоступна', function (): void {
    config(['notifications.transport.rabbitmq.dlq_queue' => 'test.dlq']);
    $broker = new FakeAmqpBroker;
    $broker->available = false;
    app()->instance(EventStream::class, consumeRabbitStream($broker));

    $this->artisan('notifications:consume', ['--once' => true, '--stream' => 'test.dlq'])
        ->expectsOutputToContain('DLQ недоступна')
        ->assertExitCode(1);
});
