<?php

declare(strict_types=1);

namespace App\Services\Notifications\Service;

use RuntimeException;
use Throwable;

/**
 * Сервис оповещений не ответил или не настроен (FR-NTF-294, ЧТЗ 8.2).
 *
 * Исключение отделено от прочих сбоев: прокси-слой превращает его в
 * понятное состояние страницы и `503`, но не в `500` с трассировкой.
 * В сообщении нет ни токена, ни адресов, ни ответа провайдера.
 */
final class NotificationServiceUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Сервис оповещений не настроен: заданы не все параметры подключения.');
    }

    public static function failed(string $method, string $path, ?int $status, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Сервис оповещений недоступен: %s %s, статус %s.',
            $method,
            $path,
            $status === null ? 'нет ответа' : (string) $status,
        ), 0, $previous);
    }
}
