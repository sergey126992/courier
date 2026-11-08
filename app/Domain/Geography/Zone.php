<?php

declare(strict_types=1);

namespace App\Domain\Geography;

use App\Domain\Pricing\Money;

/**
 * Зона обслуживания: код, название, граница и базовая ставка.
 *
 * Зона сама отвечает только на вопрос «попадает ли точка в границу»
 * и на вопрос о площади, по которой разрешается пересечение зон.
 */
final readonly class Zone
{
    public function __construct(
        private string $code,
        private string $name,
        private ServiceArea $serviceArea,
        private Money $baseFee,
        private bool $isActive = true,
    ) {}

    /**
     * Код зоны: уникален без учёта регистра и не меняется.
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Название зоны для человека.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Граница зоны.
     */
    public function serviceArea(): ServiceArea
    {
        return $this->serviceArea;
    }

    /**
     * Базовая ставка зоны.
     */
    public function baseFee(): Money
    {
        return $this->baseFee;
    }

    /**
     * Участвует ли зона в проверке новых адресов.
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Площадь зоны в квадратных километрах.
     */
    public function area(): float
    {
        return $this->serviceArea->area();
    }

    /**
     * Попадает ли точка в границу зоны.
     */
    public function contains(Coordinates $coordinates): bool
    {
        return $this->serviceArea->contains($coordinates);
    }
}
