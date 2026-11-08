<?php

declare(strict_types=1);

namespace App\Console\Scheduling;

use Illuminate\Console\Scheduling\Event;

/**
 * Переводит интервал публикатора из конфигурации в частоту расписания (FR-NTF-105).
 *
 * Laravel повторяет задачу внутри минуты только для частот, на которые 60 делится
 * нацело: 1, 2, 5, 10, 15, 20 и 30 секунд — публичного API для других «секундных»
 * частот нет. Поэтому остальные значения интервала округляются честно и предсказуемо:
 *
 * - 3…59 секунд — вверх до ближайшей поддерживаемой частоты (31…59 — до минуты),
 *   чтобы публикатор не запускался чаще заданного интервала;
 * - от 60 секунд — cron «каждые N минут» с округлением вверх (90 → каждые 2 минуты);
 * - больше 59 минут — ежечасный запуск как предельная частота расписания.
 *
 * Подминутный повтор работает благодаря `schedule:work` и `schedule:run`: планировщик
 * держит проход внутри минуты и повторяет задачи с заданным интервалом.
 */
final class OutboxPublishFrequency
{
    /**
     * Поддерживаемые Laravel частоты «раз в N секунд»: 60 делится на N нацело.
     */
    private const SECOND_FREQUENCIES = [1, 2, 5, 10, 15, 20, 30];

    /**
     * Наибольший шаг в минутах, выражаемый cron-выражением «каждые N минут».
     */
    private const MAX_CRON_MINUTES = 59;

    /**
     * Включает на событии частоту, ближайшую к заданному интервалу.
     */
    public static function apply(Event $event, int $seconds): Event
    {
        $effective = self::effectiveSeconds(max(1, $seconds));

        if ($effective < 60) {
            return match ($effective) {
                1 => $event->everySecond(),
                2 => $event->everyTwoSeconds(),
                5 => $event->everyFiveSeconds(),
                10 => $event->everyTenSeconds(),
                15 => $event->everyFifteenSeconds(),
                20 => $event->everyTwentySeconds(),
                default => $event->everyThirtySeconds(),
            };
        }

        $minutes = intdiv($effective + 59, 60);

        return $minutes > self::MAX_CRON_MINUTES
            ? $event->hourly()
            : $event->cron(sprintf('*/%d * * * *', $minutes));
    }

    /**
     * Ближайшая поддерживаемая частота, при которой задача идёт не чаще заданной.
     */
    private static function effectiveSeconds(int $seconds): int
    {
        if ($seconds >= 60) {
            return $seconds;
        }

        foreach (self::SECOND_FREQUENCIES as $supported) {
            if ($supported >= $seconds) {
                return $supported;
            }
        }

        return 60;
    }
}
