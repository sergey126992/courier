<?php

declare(strict_types=1);

use App\Metrics\StreamLagProbe;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Лаг и pending consumer group потоков Redis (ЧТЗ 9.1, FR-OPS-505)
|--------------------------------------------------------------------------
|
| Юнит-тест закрывает ветки StreamLagProbe без сети: доступный Redis,
| отсутствующий поток, отсутствующая группа, ожидающие записи разной формы,
| разбор возраста первой непрочитанной записи и отказ источника.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Зонд с конфигурацией по умолчанию.
 */
function streamLagProbe(): StreamLagProbe
{
    return new StreamLagProbe('notifications-service', 10);
}

/**
 * Описание consumer group сервиса в ответе XINFO GROUPS.
 *
 * @param array<string, mixed> $overrides переопределяющие поля
 * @return array<string, mixed>
 */
function streamLagGroup(array $overrides = []): array
{
    return array_merge([
        'name' => 'notifications-service',
        'pending' => 0,
        'lag' => 0,
        'last-delivered-id' => '0-0',
    ], $overrides);
}

it('возвращает null, когда Redis недоступен', function (): void {
    bindFakeRedis([], new RuntimeException('connection refused'));

    expect(streamLagProbe()->sample('events.orders'))->toBeNull();
});

it('возвращает нули, когда потока ещё нет', function (): void {
    bindFakeRedis(['xinfo' => new RuntimeException('NOGROUP No such key')]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});

it('возвращает null при прочей ошибке XINFO', function (): void {
    bindFakeRedis(['xinfo' => new RuntimeException('unexpected failure')]);

    expect(streamLagProbe()->sample('events.orders'))->toBeNull();
});

it('возвращает null, когда XINFO отдал не массив', function (): void {
    bindFakeRedis(['xinfo' => 'not-an-array']);

    expect(streamLagProbe()->sample('events.orders'))->toBeNull();
});

it('возвращает нули, когда группы сервиса нет', function (): void {
    bindFakeRedis(['xinfo' => ['not-array', ['name' => 'other-group']]]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});

it('считает лаг по самой старой ожидающей записи', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['pending' => 2])],
        'xpending' => [
            ['1-0', 'consumer-1', 35000, 1],
            ['2-0', 'consumer-1', 12000, 1],
        ],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 35, 'pending' => 2]);
});

it('пропускает некорректные записи XPENDING', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['pending' => 1])],
        'xpending' => [null, ['1-0'], ['2-0', 'consumer-1', 'broken'], ['3-0', 'consumer-1', 5000, 1]],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 5, 'pending' => 1]);
});

it('возвращает ноль, когда XPENDING отдал не массив', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['pending' => 1])],
        'xpending' => 'not-an-array',
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 1]);
});

it('возвращает ноль, когда ожидающих записей нет в ответе', function (): void {
    bindFakeRedis(['xinfo' => [streamLagGroup(['pending' => 1])]]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 1]);
});

it('считает возраст первой непрочитанной записи', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['pending' => 0, 'lag' => 3])],
        'xrange' => [['1-0', ['occurred_at' => '2026-11-07T09:58:00+00:00']]],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 120, 'pending' => 0]);
});

it('возвращает ноль, когда непрочитанных записей нет в ответе', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['lag' => 2])],
        'xrange' => [],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});

it('возвращает ноль при некорректной записи потока', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['lag' => 2])],
        'xrange' => [['1-0', 'broken']],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});

it('возвращает ноль без поля occurred_at', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['lag' => 2])],
        'xrange' => [['1-0', []]],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});

it('возвращает ноль при неверном occurred_at', function (): void {
    bindFakeRedis([
        'xinfo' => [streamLagGroup(['lag' => 2])],
        'xrange' => [['1-0', ['occurred_at' => 'not-a-date']]],
    ]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});

it('возвращает ноль, когда лаг не положителен', function (): void {
    bindFakeRedis(['xinfo' => [streamLagGroup(['lag' => 0])]]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);

    bindFakeRedis(['xinfo' => [streamLagGroup(['lag' => 'unknown'])]]);

    expect(streamLagProbe()->sample('events.orders'))->toBe(['lag_seconds' => 0, 'pending' => 0]);
});
