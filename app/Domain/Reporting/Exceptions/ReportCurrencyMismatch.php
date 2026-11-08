<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exceptions;

use DomainException;

/**
 * В периоде отчёта встретилась валюта, отличная от настроенной (FR-RPT-153, FR-RPT-154).
 *
 * Текст нейтрален: клиент получает код ошибки, а не коды валют и не
 * идентификаторы заказов, из-за которых отчёт не построен.
 */
final class ReportCurrencyMismatch extends DomainException
{
    public static function detected(): self
    {
        return new self('Валюты заказов в периоде не совпадают с валютой отчёта.');
    }
}
