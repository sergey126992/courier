<?php

declare(strict_types=1);

namespace App\Services\Notifications\DeviceTokens;

use App\Models\DeviceToken;

/**
 * Хранилище device-токенов поверх таблицы `device_tokens` (ЧТЗ 7.1).
 *
 * Выборка использует частичный индекс `(user_id) WHERE revoked_at IS NULL`
 * (FR-NTF-530), отзыв идемпотентен: повторно отозванный токен не переписывает
 * момент отзыва.
 */
final class EloquentDeviceTokenStore implements DeviceTokenStore
{
    public function activeFor(int $userId): array
    {
        /** @var list<DeviceToken> $tokens */
        $tokens = DeviceToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $tokens;
    }

    public function revoke(DeviceToken $deviceToken): void
    {
        if ($deviceToken->revoked_at !== null) {
            return;
        }

        $deviceToken->forceFill(['revoked_at' => now()->utc()])->save();
    }
}
