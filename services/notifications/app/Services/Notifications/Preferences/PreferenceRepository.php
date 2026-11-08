<?php

declare(strict_types=1);

namespace App\Services\Notifications\Preferences;

use App\Domain\Notifications\PreferenceRow;
use App\Models\NotificationPreference;

/**
 * Хранение предпочтений получателя (FR-NTF-451, FR-NTF-453).
 *
 * Запись выполняется upsert по `(user_id, event_type)` — уникальный ключ
 * базы, поэтому повторный `PATCH` с тем же набором строк не создаёт второй
 * строки (ЧТЗ 8.4). Сброс удаляет сохранённую настройку: отсутствие строки
 * означает дефолт матрицы M11 (FR-NTF-459).
 */
final class PreferenceRepository
{
    /**
     * Сохранённые строки пользователя по типу события.
     *
     * @return array<string, NotificationPreference>
     */
    public function savedFor(int $userId): array
    {
        $rows = [];

        $models = NotificationPreference::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        foreach ($models as $model) {
            $rows[(string) $model->event_type] = $model;
        }

        return $rows;
    }

    /**
     * Сохраняет строку предпочтений или обновляет существующую.
     */
    public function store(int $userId, PreferenceRow $row): void
    {
        NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'event_type' => $row->eventType(),
            ],
            [
                'channels' => $row->channels(),
                'quiet_hours' => $row->quietHours()?->toArray(),
                'locale' => $row->locale(),
                'critical_override' => $row->criticalOverride(),
            ],
        );
    }

    /**
     * Удаляет сохранённую строку; отсутствие строки ошибкой не считается.
     */
    public function delete(int $userId, string $eventType): void
    {
        NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event_type', $eventType)
            ->delete();
    }
}
