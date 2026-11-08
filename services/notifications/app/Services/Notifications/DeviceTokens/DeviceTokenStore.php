<?php

declare(strict_types=1);

namespace App\Services\Notifications\DeviceTokens;

use App\Models\DeviceToken;

/**
 * Хранилище активных device-токенов (FR-NTF-527, FR-NTF-528).
 *
 * Провайдеру push нужны только активные токены получателя и возможность
 * отозвать токен, который провайдер считает недействительным. Интерфейс
 * отделяет отправку от базы: unit-тесты провайдера работают без неё.
 */
interface DeviceTokenStore
{
    /**
     * Активные (не отозванные) токены получателя.
     *
     * @return list<DeviceToken>
     */
    public function activeFor(int $userId): array;

    /**
     * Отзывает токен: повторная отправка по нему не выполняется.
     */
    public function revoke(DeviceToken $deviceToken): void;
}
