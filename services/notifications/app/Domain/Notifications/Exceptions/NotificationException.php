<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use RuntimeException;

/**
 * База доменных исключений сервиса оповещений (FR-NTF-271).
 *
 * Каждое исключение несёт машиночитаемый код причины: он сохраняется
 * в журнале и попытках и не содержит персональных данных. Разделение на
 * бизнес и инфраструктуру задаёт отношение к повтору: бизнес-ошибка
 * автоматически не повторяется, инфраструктурная — повторяется.
 * Код ответа провайдера, если он известен, отдаётся отдельным методом
 * и попадает в `notification_attempts.provider_code`.
 */
abstract class NotificationException extends RuntimeException
{
    /**
     * Машиночитаемый код причины (ЧТЗ 8.5).
     */
    abstract public function errorCode(): string;

    /**
     * Код ответа внешнего провайдера, если канал его получил.
     */
    public function providerCode(): ?string
    {
        return null;
    }
}
