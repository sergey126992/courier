<?php

declare(strict_types=1);

use App\Domain\Tracking\Exceptions\TrackingPointInFuture;
use App\Domain\Tracking\Exceptions\TrackingPointOutOfSequence;
use App\Domain\Tracking\TrackingSequence;

/**
 * Текущий момент и предыдущая точка для проверок порядка.
 */
function trackingSequenceNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-05-12 10:00:00', new DateTimeZone('UTC'));
}

it('принимает точку со временем строго позже предыдущей', function (): void {
    $now = trackingSequenceNow();

    expect(static fn () => TrackingSequence::assertAccepts(
        $now->modify('-1 minute'),
        $now->modify('-2 minutes'),
        $now,
        300,
    ))->not->toThrow(TrackingPointOutOfSequence::class);
});

it('принимает первую точку назначения, если время не из будущего', function (): void {
    $now = trackingSequenceNow();

    expect(static fn () => TrackingSequence::assertAccepts($now, null, $now, 0))
        ->not->toThrow(TrackingPointOutOfSequence::class);
});

it('отклоняет точку со временем, равным предыдущей', function (): void {
    $now = trackingSequenceNow();
    $recordedAt = $now->modify('-1 minute');

    expect(static fn () => TrackingSequence::assertAccepts($recordedAt, $recordedAt, $now, 300))
        ->toThrow(TrackingPointOutOfSequence::class);
});

it('отклоняет точку со временем раньше предыдущей', function (): void {
    $now = trackingSequenceNow();

    expect(static fn () => TrackingSequence::assertAccepts(
        $now->modify('-3 minutes'),
        $now->modify('-1 minute'),
        $now,
        300,
    ))->toThrow(TrackingPointOutOfSequence::class);
});

it('принимает время из будущего в пределах допуска', function (): void {
    $now = trackingSequenceNow();

    expect(static fn () => TrackingSequence::assertAccepts($now->modify('+4 minutes'), null, $now, 300))
        ->not->toThrow(TrackingPointInFuture::class);
});

it('отклоняет время из будущего сверх допуска', function (): void {
    $now = trackingSequenceNow();

    expect(static fn () => TrackingSequence::assertAccepts($now->modify('+6 minutes'), null, $now, 300))
        ->toThrow(TrackingPointInFuture::class);
});
