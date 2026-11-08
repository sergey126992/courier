<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exceptions;

use DomainException;

/**
 * Период отчёта длиннее допустимого предела (FR-RPT-146).
 *
 * Предел приходит аргументом конструктора периода, а не читается из
 * конфигурации: домен не знает о настройках приложения.
 */
final class ReportPeriodTooLong extends DomainException
{
    public static function forLimit(int $maxDurationSeconds): self
    {
        return new self(sprintf(
            'Длительность периода превышает предел %d секунд.',
            $maxDurationSeconds,
        ));
    }
}
