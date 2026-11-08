<?php

declare(strict_types=1);

use App\Domain\Dispatch\Exceptions\CourierInactive;
use App\Domain\Dispatch\Exceptions\CourierShiftAlreadyOpen;
use App\Domain\Dispatch\Exceptions\CourierShiftNotOpen;
use App\Domain\Dispatch\Exceptions\CourierShiftOverlap;
use App\Domain\Dispatch\Exceptions\InvalidShiftPeriod;
use App\Domain\Dispatch\Exceptions\ShiftHasActiveAssignment;
use App\Domain\Dispatch\Shift;
use App\Domain\Dispatch\ShiftRules;

/**
 * Открытая смена курьера 1 с заданным началом.
 */
function domainShiftOpen(string $startedAt, ?int $zoneId = null): Shift
{
    return Shift::open(1, $zoneId, new DateTimeImmutable($startedAt));
}

/**
 * Закрытая смена курьера 1.
 */
function domainShiftClosed(string $startedAt, string $endedAt): Shift
{
    return new Shift(1, null, new DateTimeImmutable($startedAt), new DateTimeImmutable($endedAt));
}

it('открытая смена не имеет окончания', function (): void {
    $shift = domainShiftOpen('2026-05-01 09:00:00', 7);

    expect($shift->isOpen())->toBeTrue()
        ->and($shift->endedAt)->toBeNull()
        ->and($shift->zoneId)->toBe(7);
});

it('отвергает смену, у которой окончание не позже начала', function (string $endedAt): void {
    expect(static fn (): Shift => domainShiftClosed('2026-05-01 09:00:00', $endedAt))
        ->toThrow(InvalidShiftPeriod::class);
})->with([
    'раньше начала' => ['2026-05-01 08:59:59'],
    'равно началу' => ['2026-05-01 09:00:00'],
]);

it('считает открытую смену пересекающейся с любой другой', function (): void {
    $open = domainShiftOpen('2026-05-01 09:00:00');
    $candidate = domainShiftOpen('2026-05-01 23:00:00');

    expect($open->overlaps($candidate))->toBeTrue()
        ->and($candidate->overlaps($open))->toBeTrue();
});

it('не считает пересечением стык смен по времени', function (): void {
    $first = domainShiftClosed('2026-05-01 09:00:00', '2026-05-01 12:00:00');
    $second = domainShiftClosed('2026-05-01 12:00:00', '2026-05-01 15:00:00');

    expect($first->overlaps($second))->toBeFalse()
        ->and($second->overlaps($first))->toBeFalse();
});

it('находит пересечение вложенных и частично наложенных смен', function (): void {
    $shift = domainShiftClosed('2026-05-01 09:00:00', '2026-05-01 15:00:00');

    expect($shift->overlaps(domainShiftClosed('2026-05-01 10:00:00', '2026-05-01 11:00:00')))->toBeTrue()
        ->and($shift->overlaps(domainShiftClosed('2026-05-01 14:00:00', '2026-05-01 18:00:00')))->toBeTrue()
        ->and($shift->overlaps(domainShiftClosed('2026-05-01 05:00:00', '2026-05-01 10:00:00')))->toBeTrue()
        ->and($shift->overlaps(domainShiftClosed('2026-05-01 16:00:00', '2026-05-01 18:00:00')))->toBeFalse();
});

it('запрещает открытие смены неактивному курьеру', function (): void {
    expect(static function (): void {
        ShiftRules::assertCanOpen(false, [], domainShiftOpen('2026-05-01 09:00:00'));
    })->toThrow(CourierInactive::class);
});

it('запрещает вторую открытую смену', function (): void {
    $existing = [domainShiftOpen('2026-05-01 09:00:00')];

    expect(static function () use ($existing): void {
        ShiftRules::assertCanOpen(true, $existing, domainShiftOpen('2026-05-01 10:00:00'));
    })->toThrow(CourierShiftAlreadyOpen::class);
});

it('запрещает смену, пересекающуюся с закрытой', function (): void {
    $existing = [domainShiftClosed('2026-05-01 09:00:00', '2026-05-01 12:00:00')];

    expect(static function () use ($existing): void {
        ShiftRules::assertCanOpen(true, $existing, domainShiftOpen('2026-05-01 11:00:00'));
    })->toThrow(CourierShiftOverlap::class);
});

it('разрешает смену, не пересекающуюся с закрытыми', function (): void {
    $existing = [
        domainShiftClosed('2026-05-01 09:00:00', '2026-05-01 12:00:00'),
        domainShiftClosed('2026-05-01 12:00:00', '2026-05-01 15:00:00'),
    ];

    ShiftRules::assertCanOpen(true, $existing, domainShiftOpen('2026-05-01 15:00:00'));

    expect($existing)->toHaveCount(2);
});

it('запрещает закрывать смену, которая уже закрыта', function (): void {
    $shift = domainShiftClosed('2026-05-01 09:00:00', '2026-05-01 12:00:00');

    expect(static fn (): Shift => $shift->close(new DateTimeImmutable('2026-05-01 13:00:00'), false))
        ->toThrow(CourierShiftNotOpen::class);
});

it('запрещает закрывать смену при активном назначении', function (): void {
    $shift = domainShiftOpen('2026-05-01 09:00:00');

    expect(static fn (): Shift => $shift->close(new DateTimeImmutable('2026-05-01 12:00:00'), true))
        ->toThrow(ShiftHasActiveAssignment::class);
});

it('закрывает открытую смену временем окончания', function (): void {
    $shift = domainShiftOpen('2026-05-01 09:00:00');
    $closed = $shift->close(new DateTimeImmutable('2026-05-01 12:00:00'), false);

    expect($closed->isOpen())->toBeFalse()
        ->and($closed->endedAt?->format(DATE_ATOM))->toBe((new DateTimeImmutable('2026-05-01 12:00:00'))->format(DATE_ATOM))
        // Исходная смена не меняется: доменное значение неизменяемо.
        ->and($shift->isOpen())->toBeTrue();
});

it('отвергает закрытие смены временем раньше её начала', function (): void {
    $shift = domainShiftOpen('2026-05-01 09:00:00');

    expect(static fn (): Shift => $shift->close(new DateTimeImmutable('2026-05-01 08:00:00'), false))
        ->toThrow(InvalidShiftPeriod::class);
});
