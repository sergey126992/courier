<?php

declare(strict_types=1);

namespace App\Metrics;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Общее хранилище снимка метрик сервиса (FR-OPS-508).
 *
 * Счётчики инкрементируются в процессе отправки, а читает их другой процесс
 * (HTTP `/metrics` или задача расписания). Поэтому снимок живёт в кэше:
 * память процесса для этого не годится.
 *
 * Сбой кэша не роняет сервис: чтение возвращает `null`, запись — `false`,
 * а вызывающая сторона отдаёт частичные данные с кодом `degraded`
 * в журнале (FR-OPS-512).
 */
final class MetricsStore
{
    /**
     * Ключ документа счётчиков и гистограммы.
     */
    private const COUNTERS = 'counters';

    /**
     * Ключ документа «дорогих» gauge.
     */
    private const GAUGES = 'gauges';

    public function __construct(
        private readonly ?string $store,
        private readonly string $prefix,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * Документ счётчиков; `null` — кэш недоступен.
     *
     * @return array<string, mixed>|null
     */
    public function readCounters(): ?array
    {
        return $this->read(self::COUNTERS);
    }

    /**
     * Документ gauge; `null` — кэш недоступен.
     *
     * @return array<string, mixed>|null
     */
    public function readGauges(): ?array
    {
        return $this->read(self::GAUGES);
    }

    /**
     * Запись документа gauge; `false` — кэш недоступен.
     *
     * @param array<string, mixed> $document
     */
    public function writeGauges(array $document): bool
    {
        return $this->write(self::GAUGES, $document);
    }

    /**
     * Чтение, изменение и запись счётчиков.
     *
     * @param Closure(array<string, mixed>): array<string, mixed> $mutator
     */
    public function updateCounters(Closure $mutator): bool
    {
        return $this->update(self::COUNTERS, $mutator);
    }

    /**
     * Чтение, изменение и запись gauge.
     *
     * @param Closure(array<string, mixed>): array<string, mixed> $mutator
     */
    public function updateGauges(Closure $mutator): bool
    {
        return $this->update(self::GAUGES, $mutator);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        try {
            $value = $this->cache()->get($this->cacheKey($key));
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function write(string $key, array $document): bool
    {
        try {
            $this->cache()->put($this->cacheKey($key), $document, max(1, $this->ttlSeconds));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param Closure(array<string, mixed>): array<string, mixed> $mutator
     */
    private function update(string $key, Closure $mutator): bool
    {
        try {
            $current = $this->cache()->get($this->cacheKey($key));
        } catch (Throwable) {
            return false;
        }

        return $this->write($key, $mutator(is_array($current) ? $current : []));
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix.$key;
    }

    private function cache(): Repository
    {
        return Cache::store($this->store === null || $this->store === '' ? null : $this->store);
    }
}
