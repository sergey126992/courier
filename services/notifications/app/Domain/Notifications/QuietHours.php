<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Окно тихих часов получателя и расчёт отложенной отправки
 * (FR-NTF-457, FR-NTF-458, FR-NTF-491…493, FR-NTF-498).
 *
 * Значения приходят проверенными: домен не читает конфигурацию и не знает
 * про Laravel. `from == to` недопустимо (окно нулевой длины), а `from > to`
 * означает окно, пересекающее полночь (ЧТЗ 5.5). Таймзона — идентификатор
 * из `DateTimeZone::listIdentifiers()`.
 *
 * Расчёт отложенной отправки (`deferredUntil`) выполняется
 * `CarbonImmutable` в таймзоне пользователя и возвращается в UTC: момент
 * хранится в `notifications.deferred_until`. Методы `date()` и `strtotime()`
 * не используются (FR-NTF-498).
 */
final readonly class QuietHours
{
    private function __construct(
        private string $from,
        private string $to,
        private string $timezone,
    ) {}

    /**
     * Проверенное окно тихих часов.
     */
    public static function fromValues(string $from, string $to, string $timezone): self
    {
        return new self($from, $to, $timezone);
    }

    /**
     * Окно из сохранённого значения jsonb; неполное значение — окно не задано.
     *
     * Строка базы могла быть записана другой версией приложения, поэтому
     * форма проверяется при чтении и не роняет выдачу предпочтений. Если
     * таймзона в настройке отсутствует или неизвестна, применяется
     * `notifications.preferences.default_timezone` (FR-NTF-491).
     */
    public static function fromStored(mixed $value, ?string $defaultTimezone = null): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $from = $value['from'] ?? null;
        $to = $value['to'] ?? null;
        $timezone = $value['timezone'] ?? null;

        if (! is_string($from) || ! is_string($to)) {
            return null;
        }

        $zone = is_string($timezone) && $timezone !== '' ? $timezone : $defaultTimezone;

        if (! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new self($from, $to, $zone);
    }

    /**
     * Начало окна в формате `HH:MM`.
     */
    public function from(): string
    {
        return $this->from;
    }

    /**
     * Конец окна в формате `HH:MM`.
     */
    public function to(): string
    {
        return $this->to;
    }

    /**
     * Таймзона окна.
     */
    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Представление для jsonb и ответа API (ЧТЗ 8.3).
     *
     * @return array{from: string, to: string, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'timezone' => $this->timezone,
        ];
    }

    /**
     * Ближайшее окончание окна, если момент попадает в тихие часы.
     *
     * `from` включительно, `to` — исключающая граница: в `to` окно уже
     * закончилось. Окно через полночь (`from > to`) проверяется двумя
     * интервалами: `[from, 24:00)` текущих суток и `[00:00, to)` следующих.
     * Результат — момент в UTC, потому что в базе колонка `timestamptz`.
     */
    public function deferredUntil(CarbonImmutable $now): ?CarbonImmutable
    {
        // Окно нулевой длины не задаёт тихих часов.
        if ($this->from === $this->to) {
            return null;
        }

        $fromMinutes = self::minutes($this->from);
        $toMinutes = self::minutes($this->to);

        if ($fromMinutes === null || $toMinutes === null) {
            return null;
        }

        $local = $now->setTimezone(new DateTimeZone($this->timezone));
        $nowMinutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');

        $end = $local->setTime(intdiv($toMinutes, 60), $toMinutes % 60);

        if ($fromMinutes < $toMinutes) {
            // Окно внутри суток: [from, to) текущего дня.
            $inside = $nowMinutes >= $fromMinutes && $nowMinutes < $toMinutes;

            return $inside ? $end->setTimezone('UTC') : null;
        }

        // Окно через полночь: [from, 24:00) текущих суток.
        if ($nowMinutes >= $fromMinutes) {
            return $end->addDay()->setTimezone('UTC');
        }

        // [00:00, to) следующих суток — это текущий день после полуночи.
        if ($nowMinutes < $toMinutes) {
            return $end->setTimezone('UTC');
        }

        return null;
    }

    /**
     * Минуты от начала суток для значения `HH:MM`; неверный формат — null.
     */
    private static function minutes(string $time): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }
}
