<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Identity\TransportType;
use DomainException;

/**
 * Ни одна стратегия расчёта не поддерживает выбранный транспорт.
 */
final class UnsupportedTransport extends DomainException
{
    private function __construct(
        string $message,
        private readonly TransportType $transport,
    ) {
        parent::__construct($message);
    }

    public static function forTransport(TransportType $transport): self
    {
        return new self(
            sprintf('Транспорт «%s» не поддерживается расчётом.', $transport->label()),
            $transport,
        );
    }

    /**
     * Тип транспорта, оставшийся без стратегии.
     */
    public function transport(): TransportType
    {
        return $this->transport;
    }
}
