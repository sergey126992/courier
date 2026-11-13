<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\CurrencyMismatch;

/**
 * Денежная сумма в минорных единицах (копейках) с валютой.
 *
 * Неизменяемый объект: любая операция возвращает новую сумму.
 * Хранение — только целое число минорных единиц, плавающая точка
 * допускается исключительно как коэффициент умножения.
 */
final readonly class Money
{
    /**
     * Код валюты проекта.
     */
    private const DEFAULT_CURRENCY = 'RUB';

    /**
     * Знак рубля для человекочитаемого формата.
     */
    private const RUBLE_SIGN = '₽';

    private function __construct(
        private int $amount,
        private string $currency,
    ) {}

    /**
     * Создаёт сумму из целого количества минорных единиц.
     */
    public static function fromMinor(int $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self($amount, $currency);
    }

    /**
     * Создаёт сумму в рублях из копеек.
     */
    public static function rub(int $amount): self
    {
        return new self($amount, self::DEFAULT_CURRENCY);
    }

    /**
     * Количество минорных единиц (копеек).
     */
    public function amount(): int
    {
        return $this->amount;
    }

    /**
     * Код валюты.
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Сложение сумм в одной валюте.
     *
     * @throws CurrencyMismatch валюты сумм различаются
     */
    #[\NoDiscard]
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other, 'сложение');

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Вычитание сумм в одной валюте.
     *
     * @throws CurrencyMismatch валюты сумм различаются
     */
    #[\NoDiscard]
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other, 'вычитание');

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Умножение на коэффициент с округлением «половина вверх».
     *
     * Округление симметрично для отрицательных сумм: -0.5 копейки даёт -1 копейку.
     */
    #[\NoDiscard]
    public function multipliedBy(float|int $coefficient): self
    {
        $product = $this->amount * (float) $coefficient;
        $rounded = $product >= 0
            ? (int) floor($product + 0.5)
            : (int) ceil($product - 0.5);

        return new self($rounded, $this->currency);
    }

    /**
     * Сравнение сумм в одной валюте: -1, 0 или 1.
     *
     * @throws CurrencyMismatch валюты сумм различаются
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other, 'сравнение');

        return $this->amount <=> $other->amount;
    }

    /**
     * Сумма равна нулю.
     */
    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Совпадают ли суммы полностью — и значение, и валюта.
     */
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    /**
     * Представление для человека: разделитель разрядов, два знака после запятой.
     */
    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $absolute = abs($this->amount);
        $major = number_format(intdiv($absolute, 100), 0, ',', ' ');
        $minor = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $value = $sign.$major.','.$minor;

        return $this->currency === self::DEFAULT_CURRENCY
            ? $value.' '.self::RUBLE_SIGN
            : $value.' '.$this->currency;
    }

    /**
     * Строковое представление совпадает с человекочитаемым форматированием.
     */
    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @throws CurrencyMismatch валюты сумм различаются
     */
    private function assertSameCurrency(self $other, string $operation): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::forOperation($operation, $this->currency, $other->currency);
        }
    }
}
