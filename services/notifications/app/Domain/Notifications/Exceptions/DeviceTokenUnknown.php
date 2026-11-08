<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Токен устройства по идентификатору не найден при отзыве (FR-NTF-526,
 * ЧТЗ 8.3).
 *
 * Клиентская ошибка API: ответ `404` с кодом `DEVICE_TOKEN_UNKNOWN`.
 */
final class DeviceTokenUnknown extends NotificationBusinessException
{
    public function __construct(int $id)
    {
        parent::__construct(
            NotificationErrorCode::DEVICE_TOKEN_UNKNOWN,
            sprintf('Токен устройства с идентификатором %d не найден.', $id),
        );
    }
}
