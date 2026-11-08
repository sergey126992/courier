<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Тело события как объект-значение (FR-NTF-114).
 *
 * Хранит только переданные пары «ключ — значение»: состав проверяет реестр,
 * а персональные данные в payload не попадают по правилам этапа.
 */
final readonly class EventPayload
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values) {}

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Поле присутствует, даже если его значение null: отсутствие и null различаются.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Отсутствующие обязательные поля в порядке перечня.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function missing(array $keys): array
    {
        return array_values(array_filter(
            $keys,
            fn (string $key): bool => ! $this->has($key),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
