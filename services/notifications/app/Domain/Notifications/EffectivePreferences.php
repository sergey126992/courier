<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Эффективные предпочтения получателя (FR-NTF-452).
 *
 * Содержит строку для каждого типа события: сохранённую настройку
 * (`source = user`) или дефолт матрицы M11 (`source = default`). Пустой
 * набор сохранённых строк даёт матрицу без изменений (FR-NTF-459).
 */
final readonly class EffectivePreferences
{
    /**
     * @param list<EffectivePreference> $preferences строки в порядке матрицы
     */
    public function __construct(
        private int $userId,
        private array $preferences,
    ) {}

    /**
     * Идентификатор получателя, чьи предпочтения прочитаны.
     */
    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * Эффективные строки в порядке матрицы и сохранённых типов событий.
     *
     * @return list<EffectivePreference>
     */
    public function items(): array
    {
        return $this->preferences;
    }
}
