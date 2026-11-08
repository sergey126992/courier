<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Глубина цепочки замещения канала (FR-NTF-479).
 *
 * Отдельной колонки глубины нет: цепочка читается по ссылкам
 * `fallback_from_notification_id`. Класс не обращается к базе и не читает
 * текстов ошибок — он только считает число звеньев по переданной карте
 * ссылок, поэтому правило глубины проверяется unit-тестом без базы.
 *
 * Предел задаётся конфигурацией `notifications.preferences.max_fallback_depth`
 * (по умолчанию 3 — по числу каналов). Строка глубины 0 — исходное
 * уведомление события; каждая строка fallback увеличивает глубину на единицу.
 */
final class FallbackChain
{
    /**
     * Глубина строки в цепочке замещения.
     *
     * @param array<int, int|null> $links карта «id строки → id строки-родителя»
     */
    public static function depth(int $notificationId, array $links): int
    {
        $depth = 0;
        $current = $notificationId;
        // Карта неполна: обход ограничен её размером, поэтому цикл
        // в испорченных данных не подвешивает отправку.
        $guard = count($links);

        while ($guard-- > 0) {
            $parent = $links[$current] ?? null;

            if ($parent === null || $parent === $current) {
                return $depth;
            }

            $depth++;
            $current = $parent;
        }

        return $depth;
    }

    /**
     * Помещается ли следующая строка цепочки в предел глубины.
     *
     * Новая строка имела бы глубину `depth + 1`; она допустима, только если
     * эта глубина меньше предела (предел — максимальное число строк цепочки).
     */
    public static function hasRoom(int $depth, int $limit): bool
    {
        return $depth + 1 < $limit;
    }
}
