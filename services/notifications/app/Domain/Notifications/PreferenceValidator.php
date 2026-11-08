<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Events\EventType;
use App\Domain\Notifications\Exceptions\PreferenceCriticalChannelRequired;
use App\Domain\Notifications\Exceptions\PreferenceEventTypeNotAllowed;
use App\Domain\Notifications\Exceptions\PreferenceInvalidChannel;
use App\Domain\Notifications\Exceptions\PreferenceInvalidEventType;
use App\Domain\Notifications\Exceptions\PreferenceInvalidLocale;
use App\Domain\Notifications\Exceptions\PreferenceInvalidQuietHours;
use DateTimeZone;

/**
 * Проверка набора предпочтений получателя (FR-NTF-454…458, FR-NTF-460).
 *
 * Домен не читает конфигурацию и не знает про Laravel: включённые каналы,
 * поддерживаемые локали, доступные роли типы событий и критичные события
 * приходят готовыми списками. Проверка идёт в два этапа — сначала форматы
 * (тип события, канал, локаль, окно), затем бизнес-правила (email критичного
 * события и доступность типа события роли), поэтому код ошибки не зависит
 * от порядка ключей в запросе. Ни одна строка набора не считается принятой,
 * пока не проверен весь набор: частичного сохранения нет (ЧТЗ 8.4).
 */
final class PreferenceValidator
{
    /**
     * Значение `HH:MM` в пределах суток (FR-NTF-457).
     */
    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param list<string> $enabledChannels включённые каналы сервиса (FR-NTF-245)
     * @param list<string> $supportedLocales поддерживаемые локали текстов (FR-NTF-247)
     * @param list<string> $allowedEventTypes типы событий, доступные ролям получателей (FR-NTF-460)
     * @param list<string> $criticalEventTypes критичные типы событий (FR-NTF-455)
     */
    public function __construct(
        private readonly array $enabledChannels,
        private readonly array $supportedLocales,
        private readonly array $allowedEventTypes,
        private readonly array $criticalEventTypes,
        private readonly string $fallbackLocale,
    ) {}

    /**
     * Проверяет набор строк запроса целиком.
     *
     * @param list<mixed> $rows строки запроса в порядке пользователя
     * @return list<PreferenceRow> проверенные строки в порядке запроса
     *
     * @throws PreferenceInvalidEventType тип события неизвестен
     * @throws PreferenceInvalidChannel канал неизвестен или выключен
     * @throws PreferenceInvalidLocale локаль не поддерживается
     * @throws PreferenceInvalidQuietHours окно нарушает формат
     * @throws PreferenceCriticalChannelRequired у критичного события нет email
     * @throws PreferenceEventTypeNotAllowed тип события недоступен роли
     */
    public function validate(array $rows): array
    {
        $validated = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new PreferenceInvalidEventType;
            }

            $validated[] = $this->validateRow($row);
        }

        return $validated;
    }

    /**
     * @param array<mixed> $row строка запроса
     */
    private function validateRow(array $row): PreferenceRow
    {
        $eventType = $row['event_type'] ?? null;

        if (! is_string($eventType) || EventType::tryFrom($eventType) === null) {
            throw new PreferenceInvalidEventType;
        }

        if (($row['reset'] ?? null) === true) {
            $this->assertAllowedForRole($eventType);

            return PreferenceRow::reset($eventType, $this->fallbackLocale);
        }

        $channels = $this->channels($row['channels'] ?? null);
        $locale = $this->locale($row['locale'] ?? null);
        $quietHours = $this->quietHours($row['quiet_hours'] ?? null);
        $critical = $this->isCritical($eventType);

        if ($critical && ! in_array(NotificationChannel::Email->value, $channels, true)) {
            throw new PreferenceCriticalChannelRequired;
        }

        $this->assertAllowedForRole($eventType);

        return PreferenceRow::make($eventType, $channels, $quietHours, $locale, $critical);
    }

    /**
     * Проверенный список каналов без повторов (FR-NTF-454).
     *
     * @return list<string>
     */
    private function channels(mixed $value): array
    {
        if (! is_array($value)) {
            throw new PreferenceInvalidChannel;
        }

        $channels = [];

        foreach ($value as $candidate) {
            $channel = is_string($candidate) ? NotificationChannel::tryFrom($candidate) : null;

            if ($channel === null || ! in_array($channel->value, $this->enabledChannels, true)) {
                throw new PreferenceInvalidChannel;
            }

            if (! in_array($channel->value, $channels, true)) {
                $channels[] = $channel->value;
            }
        }

        return $channels;
    }

    /**
     * Проверенная локаль; пустое значение — язык текстов по умолчанию.
     */
    private function locale(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->fallbackLocale;
        }

        if (! is_string($value)) {
            throw new PreferenceInvalidLocale;
        }

        $locale = strtolower(trim($value));

        if (! in_array($locale, $this->supportedLocales, true)) {
            throw new PreferenceInvalidLocale;
        }

        return $locale;
    }

    /**
     * Проверенное окно тихих часов (FR-NTF-457, FR-NTF-458).
     */
    private function quietHours(mixed $value): ?QuietHours
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new PreferenceInvalidQuietHours;
        }

        $from = $value['from'] ?? null;
        $to = $value['to'] ?? null;
        $timezone = $value['timezone'] ?? null;

        if (! is_string($from) || ! is_string($to) || ! is_string($timezone)) {
            throw new PreferenceInvalidQuietHours;
        }

        if (preg_match(self::TIME_PATTERN, $from) !== 1 || preg_match(self::TIME_PATTERN, $to) !== 1) {
            throw new PreferenceInvalidQuietHours;
        }

        // Окно нулевой длины недопустимо; `from > to` — окно через полночь.
        if ($from === $to) {
            throw new PreferenceInvalidQuietHours;
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new PreferenceInvalidQuietHours;
        }

        return QuietHours::fromValues($from, $to, $timezone);
    }

    /**
     * Критичный ли тип события.
     */
    private function isCritical(string $eventType): bool
    {
        return in_array($eventType, $this->criticalEventTypes, true);
    }

    /**
     * Доступен ли тип события роли получателя (FR-NTF-460).
     */
    private function assertAllowedForRole(string $eventType): void
    {
        if (! in_array($eventType, $this->allowedEventTypes, true)) {
            throw new PreferenceEventTypeNotAllowed;
        }
    }
}
