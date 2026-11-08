<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeviceToken;
use Illuminate\Support\Carbon;

/**
 * Представление device-токена в API без самого токена (FR-NTF-529).
 *
 * Токен — секрет: наружу отдаются только идентификатор, владелец, платформа,
 * отпечаток и моменты времени. Отпечаток позволяет сопоставить строку
 * с журналом, не раскрывая токен.
 */
final class DeviceTokenResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(DeviceToken $token): array
    {
        return [
            'id' => (int) $token->getKey(),
            'user_id' => (int) $token->user_id,
            'platform' => (string) $token->platform,
            'fingerprint' => (string) $token->fingerprint,
            'locale' => $token->locale,
            'app_version' => $token->app_version,
            'last_seen_at' => self::moment($token->last_seen_at),
            'revoked_at' => self::moment($token->revoked_at),
        ];
    }

    private static function moment(?Carbon $value): ?string
    {
        return $value?->utc()->toIso8601ZuluString();
    }
}
