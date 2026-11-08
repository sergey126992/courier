<?php

declare(strict_types=1);

use App\Services\Events\Contracts\EventBus;
use App\Services\Events\LocalEventBus;
use App\Services\Events\RabbitMqEventBus;
use App\Services\Events\RedisStreamEventBus;

it('использует in-memory транспорт в тестовом окружении', function (): void {
    expect(config('events.transport'))->toBe('in-memory')
        ->and(app(EventBus::class))->toBeInstanceOf(LocalEventBus::class);
});

it('выбирает локальную шину для транспорта in-memory', function (): void {
    config(['events.transport' => 'in-memory']);
    app()->forgetInstance(EventBus::class);

    expect(app(EventBus::class))->toBeInstanceOf(LocalEventBus::class);
});

it('выбирает адаптер Redis Streams для транспорта redis', function (): void {
    config(['events.transport' => 'redis']);
    app()->forgetInstance(EventBus::class);

    expect(app(EventBus::class))->toBeInstanceOf(RedisStreamEventBus::class);
});

it('выбирает адаптер RabbitMQ для транспорта rabbitmq', function (): void {
    config(['events.transport' => 'rabbitmq']);
    app()->forgetInstance(EventBus::class);

    expect(app(EventBus::class))->toBeInstanceOf(RabbitMqEventBus::class);
});

it('отвергает неподдержанный транспорт', function (): void {
    config(['events.transport' => 'kafka']);
    app()->forgetInstance(EventBus::class);

    expect(fn () => app(EventBus::class))->toThrow(InvalidArgumentException::class);
});
