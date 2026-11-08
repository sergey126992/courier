<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Notifications\ChannelBindingStatus;
use App\Domain\Notifications\NotificationChannel;
use App\Models\ChannelBinding;

/**
 * Привязка канала в ответах API сервиса (ЧТЗ 8.3, FR-NTF-419).
 *
 * Набор полей — allowlist: канал, производный статус и моменты времени.
 * Адрес (`chat_id`, email) и токен в ответ не попадают, поэтому даже
 * случайное расширение модели наружу не утечёт.
 */
final class ChannelBindingResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ChannelBinding $binding): array
    {
        return [
            'channel' => (string) $binding->channel,
            'status' => self::status($binding)->value,
            'verified_at' => $binding->verified_at?->utc()->toIso8601ZuluString(),
            'revoked_at' => $binding->revoked_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * Ожидание подтверждения: токен выпущен, активной привязки нет (ЧТЗ 5.2).
     *
     * @return array<string, mixed>
     */
    public static function pending(): array
    {
        return [
            'channel' => NotificationChannel::Telegram->value,
            'status' => ChannelBindingStatus::Pending->value,
            'verified_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * Производный статус строки привязки (ЧТЗ 5.2).
     */
    private static function status(ChannelBinding $binding): ChannelBindingStatus
    {
        if ($binding->revoked_at !== null) {
            return ChannelBindingStatus::Revoked;
        }

        if ($binding->verified_at === null) {
            return ChannelBindingStatus::Pending;
        }

        return ChannelBindingStatus::Verified;
    }
}
