<?php

declare(strict_types=1);

namespace App\Services\Notifications\DeviceTokens;

use App\Domain\Notifications\Exceptions\DeviceTokenUnknown;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\DB;

/**
 * Регистрация и отзыв device-токенов через служебный API (FR-NTF-525…529).
 *
 * Регистрация идемпотентна по токену: повторная регистрация обновляет
 * владельца, платформу, локаль, версию приложения и `last_seen_at`, а не
 * создаёт вторую строку (FR-NTF-525). Отозванный ранее токен при повторной
 * регистрации снова становится активным: устройство снова сообщает о себе.
 *
 * Отзыв идемпотентен: первый вызов помечает строку, повторный возвращает
 * признак «уже отозван» без второго эффекта (FR-NTF-526). Токен в ответах
 * не возвращается (FR-NTF-529).
 */
final class DeviceTokenService
{
    /**
     * Регистрирует токен или обновляет существующую строку.
     *
     * @param array{user_id:int,token:string,platform:string,locale:?string,app_version:?string} $data
     * @return array{0: DeviceToken, 1: bool} строка и признак «создана новая»
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $token = $data['token'];
            $now = now()->utc();
            $attributes = [
                'user_id' => $data['user_id'],
                'platform' => $data['platform'],
                'locale' => $data['locale'],
                'app_version' => $data['app_version'],
                'last_seen_at' => $now,
                'revoked_at' => null,
            ];

            $device = DeviceToken::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                $device = DeviceToken::query()->create(array_merge([
                    'token' => $token,
                    'fingerprint' => DeviceToken::fingerprint($token),
                ], $attributes));

                return [$device, true];
            }

            $device->fill($attributes)->save();

            return [$device, false];
        });
    }

    /**
     * Отзывает токен идемпотентно.
     *
     * @return array{0: DeviceToken, 1: bool} строка и признак «уже был отозван»
     *
     * @throws DeviceTokenUnknown строки с таким идентификатором нет
     */
    public function revoke(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $device = DeviceToken::query()->lockForUpdate()->find($id);

            if ($device === null) {
                throw new DeviceTokenUnknown($id);
            }

            if ($device->revoked_at !== null) {
                return [$device, true];
            }

            $device->forceFill(['revoked_at' => now()->utc()])->save();

            return [$device, false];
        });
    }
}
