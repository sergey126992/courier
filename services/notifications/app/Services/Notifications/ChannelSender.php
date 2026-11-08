<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\Exceptions\NotificationBusinessException;
use App\Domain\Notifications\Exceptions\NotificationInfrastructureException;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\Notification;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\PushChannel;
use App\Services\Notifications\Channels\TelegramChannel;

/**
 * Доставка уведомления по его каналу (FR-NTF-244, FR-NTF-251…258).
 *
 * Канал и шаблон берутся из строки журнала: их записал потребитель по
 * матрице политики (FR-NTF-247). Каналы независимы — сбой email не отменяет
 * push и наоборот, потому что у каждого канала своя строка журнала и свой
 * вызов отправки (FR-NTF-244). Литеральных текстов здесь нет: строки письма
 * и push собирают каналы из lang-шаблонов сервиса (FR-NTF-258).
 */
final class ChannelSender
{
    public function __construct(
        private readonly EmailChannel $email,
        private readonly PushChannel $push,
        private readonly TelegramChannel $telegram,
    ) {}

    /**
     * @throws NotificationBusinessException канал, шаблон или контакт не годятся для отправки
     * @throws NotificationInfrastructureException инфраструктурный сбой канала
     */
    public function send(Notification $notification): void
    {
        $template = $this->template($notification);

        match ($this->channel($notification)) {
            NotificationChannel::Email => $this->email->send($notification, $template),
            NotificationChannel::Push => $this->push->send($notification, $template),
            NotificationChannel::Telegram => $this->telegram->send($notification, $template),
        };
    }

    /**
     * Шаблон из строки журнала.
     *
     * @throws NotificationBusinessException шаблон неизвестен сервису
     */
    private function template(Notification $notification): NotificationTemplate
    {
        $template = NotificationTemplate::tryFrom((string) $notification->template);

        if ($template === null) {
            throw new NotificationBusinessException(
                NotificationErrorCode::TEMPLATE_NOT_FOUND,
                'Шаблон уведомления неизвестен сервису.',
            );
        }

        return $template;
    }

    /**
     * Канал из строки журнала.
     *
     * @throws NotificationBusinessException канал неизвестен сервису
     */
    private function channel(Notification $notification): NotificationChannel
    {
        $channel = NotificationChannel::tryFrom((string) $notification->channel);

        if ($channel === null) {
            throw new NotificationBusinessException(
                NotificationErrorCode::PROGRAM_ERROR,
                'Канал уведомления неизвестен сервису.',
            );
        }

        return $channel;
    }
}
