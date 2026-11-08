<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Ряд задержек повтора отправки (FR-NTF-272, FR-OPS-213).
 *
 * Предел попыток, базовые паузы и доля разброса читаются только из
 * конфигурации сервиса. Задержка перед попыткой номер `n` — это `n`-й
 * элемент ряда; после конца ряда последняя пауза повторяется. Разброс
 * детерминирован по идентификатору уведомления: одна и та же запись
 * получает одну и ту же задержку во всех проходах поллера, поэтому
 * готовность к повтору не колеблется, а пачка разносится во времени.
 */
final class BackoffSchedule
{
    /**
     * Модуль для детерминированной доли разброса.
     *
     * Простое число: доля `id % modulus / modulus` равномерно распределена
     * по идентификаторам и воспроизводится тем же выражением в SQL-выборке.
     */
    public const JITTER_MODULUS = 997;

    /**
     * Базовые паузы между попытками в секундах.
     *
     * @return list<int>
     */
    public function delays(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config('notifications.sender.backoff_seconds', []);
        $delays = [];

        foreach ($configured as $seconds) {
            $delays[] = max(1, (int) $seconds);
        }

        return $delays;
    }

    /**
     * Предел числа попыток отправки.
     */
    public function maxAttempts(): int
    {
        return max(1, (int) config('notifications.sender.max_attempts', 1));
    }

    /**
     * Доля разброса задержки: 0 — без разброса, 0.2 — до +20%.
     */
    public function jitterRatio(): float
    {
        $ratio = (float) config('notifications.sender.backoff_jitter_ratio', 0.0);

        return min(1.0, max(0.0, $ratio));
    }

    /**
     * Задержка перед попыткой с номером `attempt` (нумерация с единицы).
     */
    public function delayFor(int $attempt, int $notificationId): int
    {
        if ($attempt <= 0) {
            return 0;
        }

        $delays = $this->delays();

        if ($delays === []) {
            return 0;
        }

        $index = min($attempt, count($delays)) - 1;
        $base = $delays[$index];

        return $base + (int) round($base * $this->jitterRatio() * $this->jitterFraction($notificationId));
    }

    /**
     * Детерминированная доля разброса в диапазоне [0, 1).
     */
    public function jitterFraction(int $notificationId): float
    {
        return ($notificationId % self::JITTER_MODULUS) / self::JITTER_MODULUS;
    }
}
