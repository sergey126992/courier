<?php

declare(strict_types=1);

use App\Domain\Reporting\Exceptions\InvalidReportPeriod;
use App\Domain\Reporting\Exceptions\ReportPeriodTooLong;
use App\Domain\Reporting\ReportPeriod;

const REPORTING_TEST_MAX_PERIOD_SECONDS = 366 * 86400;

/**
 * Период отчёта по строкам дат и пределу длительности в секундах.
 */
function reportingPeriod(string $from, string $to, int $maxDurationSeconds = REPORTING_TEST_MAX_PERIOD_SECONDS): ReportPeriod
{
    return new ReportPeriod(
        new DateTimeImmutable($from),
        new DateTimeImmutable($to),
        $maxDurationSeconds,
    );
}

it('включает начало периода', function (): void {
    $period = reportingPeriod('2026-11-01 00:00:00', '2026-12-01 00:00:00');

    expect($period->contains(new DateTimeImmutable('2026-11-01 00:00:00')))->toBeTrue();
});

it('исключает окончание периода', function (): void {
    $period = reportingPeriod('2026-11-01 00:00:00', '2026-12-01 00:00:00');

    expect($period->contains(new DateTimeImmutable('2026-12-01 00:00:00')))->toBeFalse();
});

it('включает момент за микросекунду до окончания', function (): void {
    $period = reportingPeriod('2026-11-01 00:00:00', '2026-12-01 00:00:00');

    expect($period->contains($period->to()->modify('-1 microsecond')))->toBeTrue();
});

it('отвергает окончание не позже начала', function (string $to): void {
    expect(static fn (): ReportPeriod => reportingPeriod('2026-11-01 00:00:00', $to))
        ->toThrow(InvalidReportPeriod::class);
})->with([
    'равно началу' => ['2026-11-01 00:00:00'],
    'раньше начала' => ['2026-10-31 23:59:59'],
]);

it('считает длительность в абсолютных секундах', function (): void {
    $period = reportingPeriod('2026-11-01 00:00:00', '2026-11-02 00:00:00');

    expect($period->durationSeconds())->toBe(86400)
        ->and($period->days())->toBe(1);
});

it('хранит границы периода', function (): void {
    $period = reportingPeriod('2026-11-01 00:00:00', '2026-12-01 00:00:00');

    expect($period->from()->format(DATE_ATOM))->toBe((new DateTimeImmutable('2026-11-01 00:00:00'))->format(DATE_ATOM))
        ->and($period->to()->format(DATE_ATOM))->toBe((new DateTimeImmutable('2026-12-01 00:00:00'))->format(DATE_ATOM))
        ->and($period->maxDurationSeconds())->toBe(REPORTING_TEST_MAX_PERIOD_SECONDS);
});

it('допускает период ровно в предел длительности', function (): void {
    $from = new DateTimeImmutable('2026-01-01 00:00:00');
    $period = new ReportPeriod($from, $from->modify('+366 days'), REPORTING_TEST_MAX_PERIOD_SECONDS);

    expect($period->durationSeconds())->toBe(REPORTING_TEST_MAX_PERIOD_SECONDS)
        ->and($period->days())->toBe(366);
});

it('отвергает период длиннее предела на секунду', function (): void {
    $from = new DateTimeImmutable('2026-01-01 00:00:00');

    expect(static fn (): ReportPeriod => new ReportPeriod(
        $from,
        $from->modify('+366 days')->modify('+1 second'),
        REPORTING_TEST_MAX_PERIOD_SECONDS,
    ))->toThrow(ReportPeriodTooLong::class);
});

it('считает длительность по абсолютным секундам при переходе на летнее время', function (string $from, string $to, int $seconds): void {
    $period = reportingPeriod($from, $to);

    expect($period->durationSeconds())->toBe($seconds);
})->with([
    'переход на летнее время: сутки короче на час' => ['2026-03-29 00:00:00 Europe/Berlin', '2026-03-30 00:00:00 Europe/Berlin', 23 * 3600],
    'возврат на зимнее время: сутки длиннее на час' => ['2026-10-25 00:00:00 Europe/Berlin', '2026-10-26 00:00:00 Europe/Berlin', 25 * 3600],
]);
