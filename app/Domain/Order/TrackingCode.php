<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exceptions\InvalidTrackingCode;

/**
 * Публичный код трекинга: непрозрачный идентификатор, не связанный с номером.
 *
 * Формат: префикс trk_ и случайная часть в нижнем регистре. Код не содержит
 * ни номера заказа, ни персональных данных, поэтому его можно показывать клиенту.
 */
final readonly class TrackingCode
{
    /**
     * Префикс публичного кода.
     */
    public const PREFIX = 'trk_';

    /**
     * Длина случайной части: 8 байт в шестнадцатеричном виде.
     */
    private const RANDOM_BYTES = 8;

    /**
     * Допустимая случайная часть: 8–32 символа нижнего регистра.
     */
    private const PATTERN = '/^trk_[a-z0-9]{8,32}$/';

    private function __construct(
        private string $value,
    ) {}

    /**
     * Новый случайный код: 16 шестнадцатеричных символов после префикса.
     */
    public static function generate(): self
    {
        return new self(self::PREFIX.bin2hex(random_bytes(self::RANDOM_BYTES)));
    }

    /**
     * @throws InvalidTrackingCode формат не соответствует trk_ + случайная часть
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidTrackingCode::forFormat($value);
        }

        return new self($value);
    }

    /**
     * Код целиком.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Совпадают ли коды по значению.
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
