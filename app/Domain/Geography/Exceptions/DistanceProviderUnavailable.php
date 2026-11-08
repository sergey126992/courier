<?php

declare(strict_types=1);

namespace App\Domain\Geography\Exceptions;

use DomainException;

/**
 * Провайдер расстояний недоступен: расстояние не определено.
 *
 * Нулевое расстояние вместо ошибки недопустимо — оно искажает цену и прогноз.
 */
final class DistanceProviderUnavailable extends DomainException
{
    public static function forProvider(string $provider, string $reason): self
    {
        return new self(sprintf('Провайдер расстояний «%s» недоступен: %s.', $provider, $reason));
    }
}
