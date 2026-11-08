<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use RuntimeException;

/**
 * Отказ Bot API Telegram (FR-NTF-438).
 *
 * Сообщение — постоянная строка без токена бота, `chat_id` и текста
 * сообщения. HTTP-статус, описание и `retry_after` нужны разбору ответов,
 * чтобы отличить лимит запросов, отзыв привязки и инфраструктурный сбой
 * (FR-NTF-436…439).
 */
final class TelegramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $description = '',
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Отказ Bot API по телу ответа: описание и `retry_after` без токена и чата.
     *
     * @param mixed $payload декодированное тело ответа (может быть не массивом)
     */
    public static function fromResponse(int $status, mixed $payload): self
    {
        $description = '';
        $retryAfter = null;

        if (is_array($payload)) {
            $rawDescription = $payload['description'] ?? null;

            if (is_string($rawDescription)) {
                $description = trim($rawDescription);
            }

            $parameters = $payload['parameters'] ?? null;

            if (is_array($parameters)) {
                $rawRetryAfter = $parameters['retry_after'] ?? null;

                if (is_int($rawRetryAfter)) {
                    $retryAfter = max(0, $rawRetryAfter);
                }
            }
        }

        return new self('API Telegram ответил ошибкой.', $status, $description, $retryAfter);
    }

    /**
     * HTTP-статус ответа Telegram; 0 — ответа не было (сеть, таймаут).
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Описание отказа от Bot API: используется только для распознавания
     * причины, в ответы и логи не выводится.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * `parameters.retry_after` из ответа `429`, если он пришёл (FR-NTF-436).
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
