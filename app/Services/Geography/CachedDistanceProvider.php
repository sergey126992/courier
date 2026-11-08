<?php

declare(strict_types=1);

namespace App\Services\Geography;

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Pricing\Distance;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Декоратор провайдера расстояний: кэширует результат по паре координат.
 *
 * Ключ строится по координатам, округлённым до заданного числа знаков, и
 * не зависит от направления пары. От «промаха стаи» защищает лок на ключ:
 * пока один обработчик считает расстояние, остальные ждут и читают кэш.
 * Исключение провайдера не кэшируется — сбой виден следующему вызову.
 */
final readonly class CachedDistanceProvider implements DistanceProvider
{
    public function __construct(
        private DistanceProvider $inner,
        private int $ttlSeconds,
        private int $keyDecimals,
        private int $lockTtlSeconds,
        private int $lockWaitSeconds,
    ) {}

    public function between(Coordinates $from, Coordinates $to): Distance
    {
        $key = $this->cacheKey($from, $to);
        $cached = $this->read($key);

        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock($key.':lock', $this->lockTtlSeconds);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException) {
            // Пара считается дольше предела ожидания: обработчик не блокируется,
            // читает кэш повторно и в крайнем случае считает расстояние сам.
            return $this->read($key) ?? $this->remember($key, $from, $to);
        }

        try {
            // Повторное чтение: расстояние мог посчитать обработчик, державший лок.
            return $this->read($key) ?? $this->remember($key, $from, $to);
        } finally {
            $lock->release();
        }
    }

    /**
     * Расстояние из кэша, если оно там есть.
     */
    private function read(string $key): ?Distance
    {
        $value = Cache::get($key);

        if (! is_numeric($value)) {
            return null;
        }

        return Distance::fromKilometers((float) $value);
    }

    /**
     * Расчёт и запись в кэш.
     *
     * Исключение провайдера не перехватывается: отрицательный результат
     * не попадает в кэш.
     */
    private function remember(string $key, Coordinates $from, Coordinates $to): Distance
    {
        $distance = $this->inner->between($from, $to);

        Cache::put($key, $distance->kilometers(), $this->ttlSeconds);

        return $distance;
    }

    /**
     * Ключ кэша по паре округлённых координат; направление пары не влияет.
     */
    private function cacheKey(Coordinates $from, Coordinates $to): string
    {
        $pair = [$this->coordinateKey($from), $this->coordinateKey($to)];
        sort($pair);

        return 'geography:distance:'.$pair[0].'|'.$pair[1];
    }

    private function coordinateKey(Coordinates $coordinates): string
    {
        $decimals = max(0, $this->keyDecimals);

        return number_format($coordinates->latitude(), $decimals, '.', '')
            .','
            .number_format($coordinates->longitude(), $decimals, '.', '');
    }
}
