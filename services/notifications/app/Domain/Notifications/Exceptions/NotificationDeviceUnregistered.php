<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Провайдер сообщил, что токен устройства больше не действителен
 * (`404`/`410`, `UNREGISTERED`, FR-NTF-527).
 *
 * Токен отзывается, повтор по нему не выполняется бесконечно, уведомление
 * переходит к цепочке замещения каналов M12.
 */
final class NotificationDeviceUnregistered extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(
            NotificationErrorCode::NOTIFICATION_DEVICE_UNREGISTERED,
            'Провайдер push сообщил об отозванном токене устройства.',
        );
    }
}
