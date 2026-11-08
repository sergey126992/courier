<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Надбавка к базовой стоимости: процент от промежуточного итога или фиксированная сумма.
 */
final readonly class Surcharge
{
    private function __construct(
        private BreakdownCode $code,
        private string $label,
        private ?Money $fixedAmount,
        private float $percent,
    ) {}

    /**
     * Надбавка процентом от суммы «базовая ставка + расстояние».
     */
    public static function percentage(BreakdownCode $code, string $label, float $percent): self
    {
        return new self($code, $label, null, $percent);
    }

    /**
     * Надбавка фиксированной суммой.
     */
    public static function fixed(BreakdownCode $code, string $label, Money $amount): self
    {
        return new self($code, $label, $amount, 0.0);
    }

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
     * Сумма надбавки при известном промежуточном итоге.
     */
    public function resolve(Money $subtotal): Money
    {
        return $this->fixedAmount ?? $subtotal->multipliedBy($this->percent);
    }
}
