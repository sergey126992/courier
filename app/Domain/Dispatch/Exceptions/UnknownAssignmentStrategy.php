<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DomainException;

/**
 * Правило подбора с таким именем не зарегистрировано (FR-DSP-125).
 *
 * Ошибка доменная: вызывающий код не выбирает «первого попавшегося»
 * кандидата, а настройка с опечаткой превращается в 500 INTERNAL_ERROR.
 */
final class UnknownAssignmentStrategy extends DomainException
{
    public static function named(string $name): self
    {
        return new self(sprintf('Правило подбора курьера «%s» не зарегистрировано.', $name));
    }
}
