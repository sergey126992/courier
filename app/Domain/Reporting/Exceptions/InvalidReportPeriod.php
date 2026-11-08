<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exceptions;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

/**
 * Период отчёта задан неверно: окончание не позже начала (FR-RPT-145).
 *
 * Домен не знает, как ошибка показывается клиенту: HTTP-слой переводит её
 * в код ответа и текст сообщения.
 */
final class InvalidReportPeriod extends DomainException
{
    public static function notAfter(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(sprintf(
            'Окончание периода %s должно быть позже начала %s.',
            $to->format(DateTimeInterface::ATOM),
            $from->format(DateTimeInterface::ATOM),
        ));
    }
}
