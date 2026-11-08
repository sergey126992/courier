<?php

declare(strict_types=1);

namespace App\Domain\Geography\Exceptions;

use DomainException;

/**
 * Некорректный адрес доставки.
 */
final class InvalidAddress extends DomainException
{
    public static function forField(string $field): self
    {
        return new self(sprintf('Обязательное поле адреса «%s» не заполнено.', $field));
    }
}
