<?php

declare(strict_types=1);

use App\Domain\Notifications\QuietHours;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Расчёт окна тихих часов (FR-NTF-491…493, FR-NTF-498, ЧТЗ 5.5, 9.1 №4, 5)
|--------------------------------------------------------------------------
|
| Домен не читает конфигурацию и базу: проверяются только значения окна.
| Время задаётся явно, ожидаемый момент — в UTC, потому что именно в UTC
| он хранится в `notifications.deferred_until`.
|
*/

it('возвращает окончание окна внутри суток и не трогает время вне окна', function (): void {
    $window = QuietHours::fromValues('13:00', '15:00', 'UTC');

    $inside = $window->deferredUntil(CarbonImmutable::parse('2026-11-07 14:00:00', 'UTC'));
    $before = $window->deferredUntil(CarbonImmutable::parse('2026-11-07 12:59:00', 'UTC'));
    $atEnd = $window->deferredUntil(CarbonImmutable::parse('2026-11-07 15:00:00', 'UTC'));

    expect($inside?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-07 15:00:00')
        ->and($before)->toBeNull()
        ->and($atEnd)->toBeNull();
});

it('считает from включительно, а to — окончанием окна', function (): void {
    $window = QuietHours::fromValues('13:00', '15:00', 'UTC');

    $start = $window->deferredUntil(CarbonImmutable::parse('2026-11-07 13:00:00', 'UTC'));

    expect($start?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-07 15:00:00');
});

dataset('окно 22:00–08:00 Europe/Moscow', [
    '21:59' => ['2026-11-07 21:59:00', null],
    '22:00' => ['2026-11-07 22:00:00', '2026-11-08 05:00:00'],
    '00:30' => ['2026-11-08 00:30:00', '2026-11-08 05:00:00'],
    '07:59' => ['2026-11-08 07:59:00', '2026-11-08 05:00:00'],
    '08:00' => ['2026-11-08 08:00:00', null],
]);

it('пересекает полночь и отдаёт ближайшее окончание в UTC', function (string $local, ?string $expected): void {
    $window = QuietHours::fromValues('22:00', '08:00', 'Europe/Moscow');

    $moment = CarbonImmutable::parse($local, 'Europe/Moscow');
    $deferred = $window->deferredUntil($moment);

    expect($deferred?->utc()->format('Y-m-d H:i:s'))->toBe($expected);
})->with('окно 22:00–08:00 Europe/Moscow');

it('учитывает таймзону пользователя, отличную от UTC', function (): void {
    $window = QuietHours::fromValues('22:00', '08:00', 'America/New_York');

    // 23:30 в Нью-Йорке (UTC−5) — это 04:30 UTC; окно закончится в 08:00
    // местного времени следующих суток, то есть в 13:00 UTC (FR-NTF-491).
    $moment = CarbonImmutable::parse('2026-11-07 23:30:00', 'America/New_York');
    $deferred = $window->deferredUntil($moment);

    expect($deferred?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 13:00:00');
});

it('берёт таймзону по умолчанию, если в сохранённом окне её нет', function (): void {
    $window = QuietHours::fromStored(
        ['from' => '22:00', 'to' => '08:00'],
        'Europe/Moscow',
    );

    expect($window)->not->toBeNull()
        ->and($window?->timezone())->toBe('Europe/Moscow');
});

it('не считает окном неполное или неизвестное сохранённое значение', function (): void {
    expect(QuietHours::fromStored(null))->toBeNull()
        ->and(QuietHours::fromStored('22:00'))->toBeNull()
        ->and(QuietHours::fromStored(['from' => '22:00']))->toBeNull()
        ->and(QuietHours::fromStored(['from' => '22:00', 'to' => '08:00']))->toBeNull()
        ->and(QuietHours::fromStored([
            'from' => '22:00',
            'to' => '08:00',
            'timezone' => 'Nowhere/Unknown',
        ]))->toBeNull();
});

it('не откладывает окно нулевой длины', function (): void {
    $window = QuietHours::fromValues('22:00', '22:00', 'Europe/Moscow');

    expect($window->deferredUntil(CarbonImmutable::parse('2026-11-07 22:30:00', 'Europe/Moscow')))
        ->toBeNull();
});
