<?php

declare(strict_types=1);

use App\Console\Scheduling\OutboxPublishFrequency;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Событие публикатора с частотой, выведенной из интервала в секундах.
 */
function outboxPublishEvent(int $seconds): Event
{
    $event = new Event(new CacheEventMutex(app(CacheFactory::class)), 'outbox:publish');

    return OutboxPublishFrequency::apply($event, $seconds);
}

it('включает повтор внутри минуты для поддерживаемых частот', function (int $seconds): void {
    $event = outboxPublishEvent($seconds);

    expect($event->repeatSeconds)->toBe($seconds)
        ->and($event->getExpression())->toBe('* * * * *');
})->with([[1], [2], [5], [10], [15], [20], [30]]);

it('округляет неподдерживаемый интервал вверх до ближайшей частоты', function (int $seconds, int $expected): void {
    expect(outboxPublishEvent($seconds)->repeatSeconds)->toBe($expected);
})->with([
    'три секунды' => [3, 5],
    'семь секунд' => [7, 10],
    'двадцать пять секунд' => [25, 30],
]);

it('переводит интервал в минуту, если ближайшая частота не делит её нацело', function (): void {
    $event = outboxPublishEvent(45);

    expect($event->repeatSeconds)->toBeNull()
        ->and($event->getExpression())->toBe('*/1 * * * *');
});

it('переводит интервал от минуты в cron «каждые N минут» с округлением вверх', function (int $seconds, string $expression): void {
    $event = outboxPublishEvent($seconds);

    expect($event->repeatSeconds)->toBeNull()
        ->and($event->getExpression())->toBe($expression);
})->with([
    'минута' => [60, '*/1 * * * *'],
    'полторы минуты' => [90, '*/2 * * * *'],
    'пять минут' => [300, '*/5 * * * *'],
]);

it('ограничивает интервал больше часа ежечасным запуском', function (): void {
    expect(outboxPublishEvent(7200)->getExpression())->toBe('0 * * * *');
});

it('не допускает нулевой и отрицательный интервал', function (int $seconds): void {
    expect(outboxPublishEvent($seconds)->repeatSeconds)->toBe(1);
})->with([[0], [-5]]);
