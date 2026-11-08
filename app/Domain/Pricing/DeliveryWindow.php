<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\InvalidDeliveryWindow;
use DateTimeImmutable;

/**
 * Окно доставки: момент заявки и прогноз в минутах.
 */
final readonly class DeliveryWindow
{
    public function __construct(
        private DateTimeImmutable $requestedAt,
        private int $etaMinutes,
    ) {
        if ($this->etaMinutes <= 0) {
            throw InvalidDeliveryWindow::forEtaMinutes($this->etaMinutes);
        }
    }

    /**
     * Момент заявки.
     */
    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    /**
     * Прогноз доставки в минутах.
     */
    public function etaMinutes(): int
    {
        return $this->etaMinutes;
    }

    /**
     * Ожидаемый момент доставки.
     */
    public function arrivesAt(): DateTimeImmutable
    {
        return $this->requestedAt->modify(sprintf('+%d minutes', $this->etaMinutes));
    }
}
