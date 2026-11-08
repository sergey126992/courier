<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exceptions\InvalidOrderNumber;

/**
 * Человекочитаемый номер заказа вида CRG-2026-000123.
 *
 * Порядковый номер выдаёт последовательность базы (FR-ORD-105), поэтому
 * объект только проверяет формат и разбирает составные части.
 */
final readonly class OrderNumber
{
    /**
     * Формат номера: префикс, год из четырёх цифр, шесть цифр последовательности.
     */
    private const PATTERN = '/^CRG-(\d{4})-(\d{6})$/';

    /**
     * Максимум, который вмещают шесть цифр последовательности.
     */
    private const MAX_SEQUENCE = 999999;

    private function __construct(
        private string $value,
        private int $year,
        private int $sequence,
    ) {}

    /**
     * @throws InvalidOrderNumber формат не соответствует CRG-ГГГГ-NNNNNN
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw InvalidOrderNumber::forFormat($value);
        }

        return new self($value, (int) $matches[1], (int) $matches[2]);
    }

    /**
     * @throws InvalidOrderNumber номер последовательности или год вне допустимых значений
     */
    public static function fromSequence(int $sequence, int $year): self
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw InvalidOrderNumber::forSequence($sequence);
        }

        if ($year < 1000 || $year > 9999) {
            throw InvalidOrderNumber::forYear($year);
        }

        return new self(sprintf('CRG-%04d-%06d', $year, $sequence), $year, $sequence);
    }

    /**
     * Номер целиком.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Год, к которому относится номер.
     */
    public function year(): int
    {
        return $this->year;
    }

    /**
     * Порядковый номер в году.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    /**
     * Совпадают ли номера по значению.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
