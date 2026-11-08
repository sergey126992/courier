<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use DomainException;

/**
 * История статусов доступна только для добавления (FR-ORD-126).
 */
final class StatusHistoryIsImmutable extends DomainException
{
    public static function forUpdate(mixed $id): self
    {
        return new self(sprintf('Запись истории статусов нельзя изменить (идентификатор %s).', self::describe($id)));
    }

    public static function forDelete(mixed $id): self
    {
        return new self(sprintf('Запись истории статусов нельзя удалить (идентификатор %s).', self::describe($id)));
    }

    private static function describe(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : get_debug_type($id);
    }
}
