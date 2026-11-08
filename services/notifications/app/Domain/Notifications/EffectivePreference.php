<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Эффективная строка предпочтений: сохранённая настройка или дефолт матрицы
 * (FR-NTF-452, FR-NTF-459).
 *
 * Источник помечен, чтобы интерфейс показал, что настроено пользователем,
 * а что взято из матрицы M11.
 */
final readonly class EffectivePreference
{
    /**
     * @param list<string> $channels каналы строки
     */
    public function __construct(
        public string $eventType,
        public array $channels,
        public PreferenceSource $source,
        public ?QuietHours $quietHours,
        public string $locale,
    ) {}
}
