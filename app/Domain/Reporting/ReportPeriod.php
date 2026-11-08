<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Exceptions\InvalidReportPeriod;
use App\Domain\Reporting\Exceptions\ReportPeriodTooLong;
use DateTimeImmutable;

/**
 * Период отчёта — полуинтервал `[from, to)` (FR-RPT-142, FR-RPT-149).
 *
 * Начало входит в период, окончание — нет. Длительность измеряется в
 * абсолютных секундах, поэтому переход на летнее время не меняет значение.
 * Предел длительности приходит аргументом конструктора: конфигурацию домен
 * не читает (FR-RPT-146).
 */
final readonly class ReportPeriod
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * @param int $maxDurationSeconds наибольшая допустимая длительность периода
     *
     * @throws InvalidReportPeriod окончание не позже начала
     * @throws ReportPeriodTooLong длительность больше предела
     */
    public function __construct(
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
        private int $maxDurationSeconds,
    ) {
        if ($this->to <= $this->from) {
            throw InvalidReportPeriod::notAfter($this->from, $this->to);
        }

        if ($this->durationSeconds() > $this->maxDurationSeconds) {
            throw ReportPeriodTooLong::forLimit($this->maxDurationSeconds);
        }
    }

    /**
     * Начало периода (включительно).
     */
    public function from(): DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * Окончание периода (исключительно).
     */
    public function to(): DateTimeImmutable
    {
        return $this->to;
    }

    /**
     * Принадлежит ли момент периоду: `from <= moment < to`.
     */
    public function contains(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->from && $moment < $this->to;
    }

    /**
     * Длительность в абсолютных секундах (FR-RPT-149).
     */
    public function durationSeconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }

    /**
     * Длительность в целых днях для отображения (контракт `period.days`).
     */
    public function days(): int
    {
        return intdiv($this->durationSeconds(), self::SECONDS_PER_DAY);
    }

    /**
     * Наибольшая допустимая длительность периода в секундах.
     */
    public function maxDurationSeconds(): int
    {
        return $this->maxDurationSeconds;
    }
}
