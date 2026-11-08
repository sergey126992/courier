<?php

declare(strict_types=1);

namespace App\Services\Notifications\Telegram;

use App\Domain\Notifications\Exceptions\NotificationException;
use App\Domain\Notifications\Exceptions\NotificationInfrastructureException;
use App\Domain\Notifications\Exceptions\TelegramChatNotFound;
use App\Domain\Notifications\Exceptions\TelegramRateLimited;
use App\Domain\Notifications\Exceptions\TelegramRequestRejected;
use App\Domain\Notifications\NotificationErrorCode;
use App\Services\Notifications\Channels\TelegramApiException;

/**
 * Разбор ответов Bot API Telegram в одном месте (FR-NTF-436…439).
 *
 * Транспорт (`HttpTelegramBotClient`, фейк) знает только статус, описание
 * и `retry_after`; какое это поведение — бизнес-отказ, лимит или
 * инфраструктурный сбой — решается здесь. Описание Telegram используется
 * только для распознавания причины: в коды и сообщения оно не попадает.
 *
 * | Ответ Telegram | Код | Поведение |
 * |---|---|---|
 * | `429` | `TELEGRAM_RATE_LIMITED` | инфраструктурная, повтор не раньше `retry_after` |
 * | `400 chat not found`, `403 bot was blocked by the user` | `TELEGRAM_CHAT_NOT_FOUND` | бизнес, отзыв привязки, `failed` |
 * | `401`, прочие `403`, `5xx`, сеть, таймаут, невалидный JSON | `TELEGRAM_API_FAILED` | инфраструктурная, backoff |
 * | прочие `4xx` | `TELEGRAM_REQUEST_REJECTED` | бизнес без повтора |
 */
final class TelegramResponseMapper
{
    /**
     * Нет ответа: сеть, таймаут, невалидный JSON.
     */
    private const NO_RESPONSE = 0;

    /**
     * Некорректный запрос: часть отказов означает недоступный чат.
     */
    private const BAD_REQUEST = 400;

    /**
     * Неверный токен бота: значение токена в лог не попадает (FR-NTF-438).
     */
    private const UNAUTHORIZED = 401;

    /**
     * Запрещено: отказ токена или блокировка бота пользователем.
     */
    private const FORBIDDEN = 403;

    /**
     * Лимит запросов (FR-NTF-436).
     */
    private const TOO_MANY_REQUESTS = 429;

    /**
     * Признак недоступного чата в описании `400`.
     */
    private const CHAT_NOT_FOUND = 'chat not found';

    /**
     * Признак блокировки бота в описании `403`.
     */
    private const BOT_BLOCKED = 'bot was blocked by the user';

    /**
     * Доменная ошибка по отказу Bot API.
     */
    public function map(TelegramApiException $failure): NotificationException
    {
        $status = $failure->status();
        $description = mb_strtolower($failure->description());

        if ($status === self::TOO_MANY_REQUESTS) {
            return new TelegramRateLimited($failure->retryAfter() ?? $this->defaultRetryAfter());
        }

        if ($status === self::BAD_REQUEST) {
            return str_contains($description, self::CHAT_NOT_FOUND)
                ? new TelegramChatNotFound
                : new TelegramRequestRejected;
        }

        if ($status === self::FORBIDDEN) {
            return str_contains($description, self::BOT_BLOCKED)
                ? new TelegramChatNotFound
                : $this->apiFailed();
        }

        if ($status === self::UNAUTHORIZED || $status === self::NO_RESPONSE || $status >= 500) {
            return $this->apiFailed();
        }

        if ($status >= 400) {
            return new TelegramRequestRejected;
        }

        // Ответ `ok: false` с кодом 2xx или нечитаемое тело: сбой API.
        return $this->apiFailed();
    }

    /**
     * Инфраструктурный отказ: повтор по backoff.
     */
    private function apiFailed(): NotificationInfrastructureException
    {
        return new NotificationInfrastructureException(
            NotificationErrorCode::TELEGRAM_API_FAILED,
            'Отправка сообщения через API Telegram не выполнена.',
        );
    }

    /**
     * `retry_after` по умолчанию, если Telegram его не прислал (FR-NTF-443).
     */
    private function defaultRetryAfter(): int
    {
        return max(0, (int) config('notifications.telegram.retry_after_default_seconds', 30));
    }
}
