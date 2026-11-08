<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Notifications\EffectivePreference;
use App\Domain\Notifications\EffectivePreferences;

/**
 * Эффективные предпочтения в ответах API сервиса (ЧТЗ 8.3, FR-NTF-452).
 *
 * Набор полей — allowlist: тип события, каналы, источник строки, окно тихих
 * часов и локаль. Персональных данных в ответе нет: каналы и окно не
 * раскрывают адресов, а `user_id` пришёл из доверенного вызова (FR-NTF-461).
 */
final class PreferenceResource
{
    /**
     * @return array{user_id: int, preferences: list<array<string, mixed>>}
     */
    public static function toArray(EffectivePreferences $preferences): array
    {
        return [
            'user_id' => $preferences->userId(),
            'preferences' => array_map(
                static fn (EffectivePreference $item): array => [
                    'event_type' => $item->eventType,
                    'channels' => $item->channels,
                    'source' => $item->source->value,
                    'quiet_hours' => $item->quietHours?->toArray(),
                    'locale' => $item->locale,
                ],
                $preferences->items(),
            ),
        ];
    }
}
