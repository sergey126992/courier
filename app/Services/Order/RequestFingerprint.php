<?php

declare(strict_types=1);

namespace App\Services\Order;

/**
 * Отпечаток тела запроса для ключа идемпотентности.
 *
 * Порядок ключей в JSON не влияет на результат, поэтому повтор с тем же телом,
 * но другим порядком полей считается тем же запросом (FR-ORD-108).
 */
final class RequestFingerprint
{
    /**
     * Хеш канонического представления данных запроса.
     *
     * @param array<string, mixed> $data
     */
    public function of(array $data): string
    {
        return hash('sha256', (string) json_encode($this->normalize($data), JSON_UNESCAPED_UNICODE));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
