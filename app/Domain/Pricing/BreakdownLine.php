<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Строка разбивки цены: код, название и сумма.
 */
final readonly class BreakdownLine
{
    public function __construct(
        private BreakdownCode $code,
        private string $label,
        private Money $amount,
    ) {}

    /**
     * Код строки разбивки.
     */
    public function code(): BreakdownCode
    {
        return $this->code;
    }

    /**
     * Название строки для клиента.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * Сумма строки.
     */
    public function amount(): Money
    {
        return $this->amount;
    }
}
