<?php

declare(strict_types=1);

use App\Services\Events\RedisEventStream;

/*
|--------------------------------------------------------------------------
| Адаптер Redis Streams без сети (ЧТЗ 9.1, 9.5)
|--------------------------------------------------------------------------
|
| Подключение Redis подменяется FakeRedisConnection: проверяются создание
| группы, чтение и захват с разбором ответа Predis, ack и публикация,
| границы и отказ источника. Реальный Redis не нужен.
|
*/

/**
 * Подмена ответа единственной команды адаптера (`executeRaw`).
 *
 * @param mixed $response ответ Redis
 */
function bindStreamRedis(mixed $response, ?Throwable $failure = null): void
{
    bindFakeRedis(['executeraw' => $response], $failure);
}

it('создаёт consumer group и считает существующую группу успехом', function (): void {
    bindStreamRedis(true);

    expect((new RedisEventStream(''))->ensureGroup('events.orders', 'g'))->toBeTrue();

    bindFakeRedis([], new RuntimeException('BUSYGROUP Consumer Group name already exists'));

    expect((new RedisEventStream(''))->ensureGroup('events.orders', 'g'))->toBeTrue();

    bindFakeRedis([], new RuntimeException('connection refused'));

    expect((new RedisEventStream(''))->ensureGroup('events.orders', 'g'))->toBeFalse();
});

it('проверяет границы пачки при чтении и захвате', function (): void {
    $stream = new RedisEventStream('');

    expect(fn (): ?array => $stream->read('events.orders', 'g', 'c', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ?array => $stream->reclaim('events.orders', 'g', 'c', 0, 0))->toThrow(InvalidArgumentException::class);
});

it('возвращает null при отказе чтения и захвата', function (): void {
    bindFakeRedis([], new RuntimeException('down'));
    $stream = new RedisEventStream('');

    expect($stream->read('events.orders', 'g', 'c', 10))->toBeNull()
        ->and($stream->reclaim('events.orders', 'g', 'c', 0, 10))->toBeNull();
});

it('возвращает пустой список на неразборчивый ответ чтения', function (): void {
    bindStreamRedis('not-an-array');

    expect((new RedisEventStream(''))->read('events.orders', 'g', 'c', 10))->toBe([]);
});

it('разбирает ответ XREADGROUP с картой полей', function (): void {
    bindStreamRedis([
        ['events.orders', [['1-0', ['event_type' => 'order.delivered', 'count' => 5]]]],
    ]);

    $messages = (new RedisEventStream(''))->read('events.orders', 'g', 'c', 10) ?? [];

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->id)->toBe('1-0')
        ->and($messages[0]->field('event_type'))->toBe('order.delivered')
        ->and($messages[0]->field('count'))->toBe('5');
});

it('разбирает плоский список полей записи', function (): void {
    bindStreamRedis([
        ['events.orders', [['1-0', ['event_type', 'order.delivered', 'count', 5]]]],
    ]);

    $messages = (new RedisEventStream(''))->read('events.orders', 'g', 'c', 10) ?? [];

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->field('event_type'))->toBe('order.delivered')
        ->and($messages[0]->field('count'))->toBe('5');
});

it('пропускает некорректные записи и имена полей', function (): void {
    bindStreamRedis([
        'not-a-reply',
        ['events.orders', 'not-entries'],
        ['events.orders', [
            'not-an-entry',
            ['no-id'],
            ['2-0', 'not-fields'],
            ['3-0', [['array-name'], 'value']],
        ]],
    ]);

    $messages = (new RedisEventStream(''))->read('events.orders', 'g', 'c', 10) ?? [];

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->id)->toBe('3-0')
        ->and($messages[0]->fields)->toBe([]);
});

it('возвращает пустой список на неразборчивый ответ захвата', function (): void {
    bindStreamRedis(['0-0']);

    expect((new RedisEventStream(''))->reclaim('events.orders', 'g', 'c', 0, 10))->toBe([]);
});

it('разбирает ответ XAUTOCLAIM в сообщения потока', function (): void {
    bindStreamRedis(['0-0', [['1-0', ['event_type' => 'order.delivered']]], []]);

    $messages = (new RedisEventStream(''))->reclaim('events.orders', 'g', 'c', 0, 10) ?? [];

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->id)->toBe('1-0')
        ->and($messages[0]->field('event_type'))->toBe('order.delivered');
});

it('подтверждает сообщение и сообщает об отказе подтверждения', function (): void {
    bindStreamRedis(1);

    expect((new RedisEventStream(''))->acknowledge('events.orders', 'g', '1-0'))->toBeTrue();

    bindFakeRedis([], new RuntimeException('down'));

    expect((new RedisEventStream(''))->acknowledge('events.orders', 'g', '1-0'))->toBeFalse();
});

it('публикует сообщение и сообщает об отказе транспорта', function (): void {
    bindStreamRedis(1);

    expect((new RedisEventStream('prefix:'))->publish('events.notifications', ['event_type' => 'notification.sent']))->toBeTrue();

    bindFakeRedis([], new RuntimeException('down'));

    expect((new RedisEventStream(''))->publish('events.notifications', ['event_type' => 'notification.sent']))->toBeFalse();
});
