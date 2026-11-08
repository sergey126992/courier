<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Symfony\Component\HttpFoundation\Response;

/**
 * Итог ручного повтора уведомления из админ-интерфейса (ЧТЗ 8.2).
 *
 * Значения совпадают с ответами API сервиса: `202` — повтор поставлен,
 * `404` — уведомления нет, `409` — повтор невозможен (`sent` или запись
 * не ждёт повтора), `503` — активный отправитель недоступен.
 */
enum RetryOutcome: string
{
    case Accepted = 'accepted';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case Unavailable = 'unavailable';

    public function status(): int
    {
        return match ($this) {
            self::Accepted => Response::HTTP_ACCEPTED,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::Conflict => Response::HTTP_CONFLICT,
            self::Unavailable => Response::HTTP_SERVICE_UNAVAILABLE,
        };
    }
}
