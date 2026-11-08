<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Кэш публичного ответа трекинга (FR-TRK-137).
 *
 * Ключ — только код трекинга: статус в ключ не входит, иначе для его чтения
 * нужен был бы запрос к базе и кэш перестал бы защищать от повторных чтений.
 * Смена статуса сбрасывает значение явно из одного места (см. AppServiceProvider),
 * а на случай пропущенного сброса срок жизни короткий — секунды, а не минуты.
 * Срок разбрасывается jitter'ом, чтобы одновременные открытия не обновляли
 * кэш одной волной.
 */
final readonly class PublicTrackingCache
{
    /**
     * Префикс ключа: код трекинга — единственная изменяемая часть.
     */
    private const KEY_PREFIX = 'tracking:public:';

    public function __construct(
        private int $ttlSeconds,
        private float $jitterRatio,
    ) {}

    /**
     * Значение из кэша или результат загрузчика, положенный в кэш.
     *
     * Исключение загрузчика не кэшируется: несуществующий код каждый раз
     * даёт 404, а не запоминается как ответ.
     *
     * @param Closure(): array<string, mixed> $loader
     * @return array<string, mixed>
     */
    public function remember(string $trackingCode, Closure $loader): array
    {
        /** @var array<string, mixed> $payload */
        $payload = Cache::remember(self::key($trackingCode), $this->ttl(), $loader);

        return $payload;
    }

    /**
     * Сброс значения: вызывается при смене статуса заказа.
     */
    public function forget(string $trackingCode): void
    {
        Cache::forget(self::key($trackingCode));
    }

    /**
     * Ключ кэша по коду трекинга.
     */
    public static function key(string $trackingCode): string
    {
        return self::KEY_PREFIX.$trackingCode;
    }

    /**
     * Срок жизни с разбросом: база ± доля из конфигурации, но не меньше секунды.
     */
    private function ttl(): int
    {
        $base = max(1, $this->ttlSeconds);
        $ratio = min(max($this->jitterRatio, 0.0), 1.0);

        if ($ratio === 0.0) {
            return $base;
        }

        // Разброс в пределах ±ratio: random_int в тысячных долях единицы.
        $factor = 1 + (random_int(-1000, 1000) / 1000) * $ratio;

        return max(1, (int) round($base * $factor));
    }
}
