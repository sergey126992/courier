<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Проверенная строка предпочтений получателя (FR-NTF-453…458, ЧТЗ 8.3).
 *
 * Строка либо задаёт каналы события, либо означает сброс (`reset: true`):
 * сброс удаляет сохранённую строку и возвращает дефолт матрицы. Каналы
 * уже проверены валидатором: значения — из `NotificationChannel`, а пустой
 * список у некритичного события означает «не уведомлять» (FR-NTF-456).
 * Флаг `critical_override` отмечает строку, где сервис принудительно
 * оставляет email критичного события (FR-NTF-455).
 */
final readonly class PreferenceRow
{
    /**
     * @param list<string> $channels проверенные каналы в порядке запроса
     */
    private function __construct(
        private string $eventType,
        private array $channels,
        private ?QuietHours $quietHours,
        private string $locale,
        private bool $reset,
        private bool $criticalOverride,
    ) {}

    /**
     * Строка с каналами, окном тихих часов и локалью.
     *
     * @param list<string> $channels
     */
    public static function make(
        string $eventType,
        array $channels,
        ?QuietHours $quietHours,
        string $locale,
        bool $criticalOverride,
    ): self {
        return new self($eventType, $channels, $quietHours, $locale, false, $criticalOverride);
    }

    /**
     * Строка сброса: сохранённая настройка типа события удаляется.
     */
    public static function reset(string $eventType, string $locale): self
    {
        return new self($eventType, [], null, $locale, true, false);
    }

    /**
     * Тип события строки.
     */
    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * Каналы строки в порядке запроса; пустой список — «не уведомлять».
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Окно тихих часов строки; `null` — окно не задано.
     */
    public function quietHours(): ?QuietHours
    {
        return $this->quietHours;
    }

    /**
     * Локаль текстов уведомлений.
     */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Строка удаляет сохранённую настройку.
     */
    public function isReset(): bool
    {
        return $this->reset;
    }

    /**
     * Сервис принудительно оставил email критичного события.
     */
    public function criticalOverride(): bool
    {
        return $this->criticalOverride;
    }
}
