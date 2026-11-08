<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\NotificationTemplate;
use App\Models\Notification;
use App\Services\Notifications\NotificationMessageContext;
use App\Services\Notifications\NotificationTexts;

/**
 * Канал push: сообщение фейковому провайдеру (FR-NTF-253…255).
 *
 * Текст собирается из тех же lang-шаблонов: тело и строка трекинга. Локаль
 * push — язык текстов сервиса: у канала нет контакта, из которого берётся
 * язык письма (FR-NTF-247). Провайдер приходит по контракту PushProvider:
 * на M11 это журнал-симулятор, внешней сети и device-токенов нет
 * (FR-NTF-253…255).
 */
final class PushChannel
{
    public function __construct(
        private readonly NotificationTexts $texts,
        private readonly NotificationMessageContext $context,
        private readonly PushProvider $provider,
    ) {}

    public function send(Notification $notification, NotificationTemplate $template): void
    {
        $lines = $this->texts->lines(
            $template,
            null,
            $this->context->replacements($notification),
        );

        $body = trim(implode(' ', array_filter(
            [$lines['body'] ?? '', $lines['tracking'] ?? ''],
            static fn (string $line): bool => $line !== '',
        )));

        $this->provider->send(
            (int) $notification->getKey(),
            (int) $notification->recipient_id,
            $template,
            $body,
        );
    }
}
