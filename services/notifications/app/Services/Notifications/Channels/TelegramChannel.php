<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\Exceptions\NotificationChannelUnavailable;
use App\Domain\Notifications\Exceptions\NotificationException;
use App\Domain\Notifications\Exceptions\TelegramChatNotFound;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationTemplate;
use App\Logging\TechnicalLogger;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\NotificationCounters;
use App\Services\Notifications\NotificationMessageContext;
use App\Services\Notifications\NotificationTexts;
use App\Services\Notifications\Telegram\TelegramResponseMapper;

/**
 * Канал Telegram: сообщение бота по активной привязке (FR-NTF-431…440).
 *
 * Текст собирается из тех же lang-шаблонов, что email и push: номер
 * заказа, статус и ссылка трекинга. Лимит сообщения берётся из
 * конфигурации; при превышении сокращается текст до ссылки, полная ссылка
 * трекинга сохраняется (FR-NTF-435).
 *
 * Адрес — `chat_id` из активной привязки `channel_bindings`
 * (`channel = telegram`, `revoked_at IS NULL`). Нет привязки — канал
 * недоступен заранее, это бизнес-ошибка без повтора (FR-NTF-440).
 * `TelegramChannel` не знает про HTTP и не формирует URL: сеть скрыта
 * за контрактом `TelegramBotClient`, ответы разбирает
 * `TelegramResponseMapper` в одном месте (FR-NTF-433).
 */
final class TelegramChannel
{
    /**
     * Разделитель текста и строки трекинга.
     */
    private const SEPARATOR = "\n\n";

    public function __construct(
        private readonly NotificationTexts $texts,
        private readonly NotificationMessageContext $context,
        private readonly TelegramBotClient $client,
        private readonly TelegramResponseMapper $mapper,
        private readonly NotificationCounters $counters,
        private readonly TechnicalLogger $logger,
    ) {}

    /**
     * @throws NotificationChannelUnavailable активной привязки нет
     * @throws NotificationException отказ Bot API с доменным кодом
     */
    public function send(Notification $notification, NotificationTemplate $template): void
    {
        $binding = $this->activeBinding((int) $notification->recipient_id);

        if ($binding === null) {
            throw new NotificationChannelUnavailable(NotificationChannel::Telegram);
        }

        $lines = $this->texts->lines(
            $template,
            null,
            $this->context->replacements($notification),
        );

        $text = $this->compose($lines, (int) config('notifications.telegram.message_limit', 4096));

        try {
            $this->client->sendMessage((string) $binding->address, $text);
        } catch (TelegramApiException $exception) {
            $mapped = $this->mapper->map($exception);

            // Ошибка Telegram по коду ответа: счётчик для агрегата и запись
            // журнала без `chat_id`, описания Telegram и текста сообщения
            // (FR-OPS-413, FR-OPS-411).
            $this->counters->recordTelegramError($this->errorBucket($exception->status()));

            $fields = [
                'notification_id' => (int) $notification->getKey(),
                'user_id' => (int) $notification->recipient_id,
                'channel' => NotificationChannel::Telegram->value,
                'event_type' => (string) $notification->event_type,
                'error_code' => $mapped->errorCode(),
            ];

            $retryAfter = $exception->retryAfter();

            if ($retryAfter !== null) {
                $fields['retry_after'] = $retryAfter;
            }

            $this->logger->warning('Отправка сообщения Telegram завершилась ошибкой.', $fields);

            // Детерминированная недоступность чата отзывает привязку,
            // чтобы последующие уведомления не повторяли отказ (FR-NTF-437, 439).
            if ($mapped instanceof TelegramChatNotFound) {
                $this->revoke($binding);
            }

            throw $mapped;
        }
    }

    /**
     * Активная привязка получателя: одна на пару «пользователь — канал».
     */
    private function activeBinding(int $userId): ?ChannelBinding
    {
        return ChannelBinding::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Telegram->value)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Отзыв привязки: строка сохраняется в истории (FR-NTF-417, FR-NTF-418).
     */
    private function revoke(ChannelBinding $binding): void
    {
        $binding->forceFill(['revoked_at' => now()->utc()])->save();
    }

    /**
     * Код ответа Telegram в счётчике: `400`, `403`, `429`, `5xx`, `timeout`.
     *
     * `0` — ответа не было (сеть, таймаут). Прочие коды (`401` и другие)
     * попадают в `other`: их причина та же, что у инфраструктурного сбоя,
     * но выдавать их за `5xx` в счётчике было бы неверно.
     */
    private function errorBucket(int $status): string
    {
        return match (true) {
            $status === 0 => 'timeout',
            $status === 400 => '400',
            $status === 403 => '403',
            $status === 429 => '429',
            $status >= 500 => '5xx',
            default => 'other',
        };
    }

    /**
     * Текст сообщения в пределах лимита с сохранением полной ссылки трекинга.
     *
     * @param array<string, string> $lines строки шаблона из языка сервиса
     */
    private function compose(array $lines, int $limit): string
    {
        $limit = max(1, $limit);
        $body = trim($lines['body'] ?? '');
        $tracking = trim($lines['tracking'] ?? '');

        if ($tracking === '') {
            return mb_substr($body, 0, $limit);
        }

        $text = $body === '' ? $tracking : $body.self::SEPARATOR.$tracking;

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Сокращается только текст до ссылки: ссылка остаётся целой даже
        // если она одна длиннее лимита (FR-NTF-435).
        $available = $limit - mb_strlen($tracking) - mb_strlen(self::SEPARATOR);

        if ($available < 1) {
            return $tracking;
        }

        $head = rtrim(mb_substr($body, 0, $available));

        return $head === '' ? $tracking : $head.self::SEPARATOR.$tracking;
    }
}
