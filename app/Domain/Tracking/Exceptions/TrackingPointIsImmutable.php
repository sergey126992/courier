<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Exceptions;

use DomainException;

/**
 * Геоточка доступна только для добавления (FR-TRK-102).
 */
final class TrackingPointIsImmutable extends DomainException
{
    public static function forUpdate(mixed $id): self
    {
        return new self(sprintf('Геоточку нельзя изменить (идентификатор %s).', self::describe($id)));
    }

    public static function forDelete(mixed $id): self
    {
        return new self(sprintf('Геоточку нельзя удалить (идентификатор %s).', self::describe($id)));
    }

    private static function describe(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : get_debug_type($id);
    }
}
