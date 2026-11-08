<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Identity\TransportType;
use DomainException;

/**
 * Посылка тяжелее допустимого для выбранного транспорта.
 */
final class ParcelTooHeavyForTransport extends DomainException
{
    private function __construct(
        string $message,
        private readonly int $maxWeightGrams,
        private readonly TransportType $transport,
    ) {
        parent::__construct($message);
    }

    public static function forParcel(TransportType $transport, int $maxWeightGrams): self
    {
        return new self(
            sprintf(
                'Посылка тяжелее допустимого для транспорта «%s»: максимум %d г.',
                $transport->label(),
                $maxWeightGrams,
            ),
            $maxWeightGrams,
            $transport,
        );
    }

    /**
     * Предельный вес транспорта в граммах.
     */
    public function maxWeightGrams(): int
    {
        return $this->maxWeightGrams;
    }

    /**
     * Тип транспорта, для которого вес превышен.
     */
    public function transport(): TransportType
    {
        return $this->transport;
    }
}
