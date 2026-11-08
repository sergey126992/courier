<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Расписание часов пик: интервалы времени в заданном часовом поясе.
 *
 * Границы интервалов включаются. Часовой пояс передаётся снаружи,
 * домен не обращается к конфигурации приложения.
 */
final readonly class PeakHourSchedule
{
    /**
     * @param list<array{from: string, to: string}> $intervals интервалы в формате «ЧЧ:ММ»
     */
    public function __construct(
        private array $intervals,
        private string $timezone,
    ) {}

    /**
     * Попадает ли момент в один из интервалов часа пик.
     */
    public function isPeakHour(DateTimeImmutable $at): bool
    {
        $time = $at->setTimezone(new DateTimeZone($this->timezone))->format('H:i');

        foreach ($this->intervals as $interval) {
            if ($time >= $interval['from'] && $time <= $interval['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Часовой пояс, в котором трактуются интервалы.
     */
    public function timezone(): string
    {
        return $this->timezone;
    }
}
