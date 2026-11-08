<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * Остаток пути до адреса доставки (FR-TRK-122, FR-TRK-123).
 *
 * Значение и его источник неразделимы: клиент не угадывает, посчитан остаток
 * или показан зафиксированный прогноз. Минуты есть только у посчитанного
 * остатка — у planned их нет ни при каких условиях.
 */
final readonly class RemainingEta
{
    private function __construct(
        private EtaSource $source,
        private ?int $minutes,
    ) {}

    /**
     * Остаток посчитан по последней позиции.
     */
    public static function computed(int $minutes): self
    {
        return new self(EtaSource::Computed, $minutes);
    }

    /**
     * Остаток не считается: отдаётся зафиксированный прогноз.
     */
    public static function planned(): self
    {
        return new self(EtaSource::Planned, null);
    }

    /**
     * Источник остатка для ответа API.
     */
    public function source(): EtaSource
    {
        return $this->source;
    }

    /**
     * Остаток в минутах; null — остаток не вычислялся.
     */
    public function minutes(): ?int
    {
        return $this->minutes;
    }

    /**
     * Остаток посчитан (источник computed).
     */
    public function isComputed(): bool
    {
        return $this->source === EtaSource::Computed;
    }
}
