<?php

declare(strict_types=1);

use App\Services\Notifications\BackoffSchedule;

/*
|--------------------------------------------------------------------------
| Ряд backoff и разброс (FR-NTF-272, ЧТЗ 9.1 п. 3)
|--------------------------------------------------------------------------
|
| Проверяется расчёт задержки по конфигурации: значения не выходят за
| пределы базовой паузы плюс допустимый разброс, последняя пауза
| повторяется, а для одной записи задержка детерминирована.
|
*/

beforeEach(function (): void {
    config([
        'notifications.sender.backoff_seconds' => [10, 30, 60, 120, 300],
        'notifications.sender.backoff_jitter_ratio' => 0.2,
        'notifications.sender.max_attempts' => 5,
    ]);
});

it('берёт предел попыток и базовые паузы из конфигурации', function (): void {
    $schedule = app(BackoffSchedule::class);

    expect($schedule->delays())->toBe([10, 30, 60, 120, 300])
        ->and($schedule->maxAttempts())->toBe(5)
        ->and($schedule->jitterRatio())->toBe(0.2);
});

it('рассчитывает задержку по номеру попытки в пределах разброса', function (): void {
    $schedule = app(BackoffSchedule::class);
    $bases = [1 => 10, 2 => 30, 3 => 60, 4 => 120, 5 => 300];

    foreach ($bases as $attempt => $base) {
        $delay = $schedule->delayFor($attempt, 42);

        expect($delay)->toBeGreaterThanOrEqual($base)
            ->and($delay)->toBeLessThanOrEqual((int) round($base * 1.2));
    }
});

it('повторяет последнюю паузу после конца ряда', function (): void {
    $schedule = app(BackoffSchedule::class);

    expect($schedule->delayFor(6, 42))->toBe($schedule->delayFor(300, 42));

    $last = $schedule->delayFor(5, 42);

    expect($schedule->delayFor(6, 42))->toBeGreaterThanOrEqual(300)
        ->and($schedule->delayFor(6, 42) - 300)->toBe($last - 300);
});

it('даёт одну и ту же задержку для одного уведомления', function (): void {
    $schedule = app(BackoffSchedule::class);

    expect($schedule->delayFor(2, 7))->toBe($schedule->delayFor(2, 7))
        ->and($schedule->jitterFraction(7))->toBeGreaterThanOrEqual(0.0)
        ->and($schedule->jitterFraction(7))->toBeLessThan(1.0);
});

it('не добавляет разброс, когда доля равна нулю', function (): void {
    config(['notifications.sender.backoff_jitter_ratio' => 0.0]);

    $schedule = app(BackoffSchedule::class);

    expect($schedule->delayFor(1, 7))->toBe(10)
        ->and($schedule->delayFor(5, 7))->toBe(300);
});
