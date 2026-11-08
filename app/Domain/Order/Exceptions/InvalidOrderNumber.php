<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use DomainException;

/**
 * Некорректный номер заказа.
 */
final class InvalidOrderNumber extends DomainException
{
    public static function forFormat(string $value): self
    {
        return new self(sprintf(
            'Номер заказа обязан иметь вид CRG-ГГГГ-NNNNNN, получено «%s».',
            $value,
        ));
    }

    public static function forSequence(int $sequence): self
    {
        return new self(sprintf('Порядковый номер заказа обязан быть от 1 до 999999, получено %d.', $sequence));
    }

    public static function forYear(int $year): self
    {
        return new self(sprintf('Год в номере заказа обязан быть четырёхзначным, получено %d.', $year));
    }
}
