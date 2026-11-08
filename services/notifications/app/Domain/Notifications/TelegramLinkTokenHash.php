<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Хеширование и сравнение токена привязки Telegram (FR-NTF-413).
 *
 * В базе хранится только SHA-256 значения: открытый токен возвращается
 * один раз и в журнал не попадает (FR-NTF-420). Сравнение выполняется
 * постоянным по времени способом, поэтому по времени ответа нельзя
 * подобрать токен посимвольно.
 */
final class TelegramLinkTokenHash
{
    /**
     * Алгоритм хеширования токена привязки.
     */
    public const ALGORITHM = 'sha256';

    /**
     * Хеш открытого значения токена.
     */
    public static function hash(string $plain): string
    {
        return hash(self::ALGORITHM, $plain);
    }

    /**
     * Постоянное по времени сравнение открытого значения с сохранённым хешем.
     */
    public static function matches(string $plain, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($plain));
    }
}
