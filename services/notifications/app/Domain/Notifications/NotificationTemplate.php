<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Events\EventType;
use App\Domain\Notifications\Exceptions\NotificationTemplateNotFound;

/**
 * Стабильные имена шаблонов уведомлений сервиса (FR-NTF-247, FR-NTF-258).
 *
 * Имя шаблона — пара «тип события — канал» в нотации snake_case: по нему
 * находятся тексты в lang/{locale}/notifications.php. Переименование меняет
 * данные журнала, поэтому имя считается контрактом. Шаблона для канала,
 * которого нет в матрице, не существует — это бизнес-ошибка без повтора.
 */
enum NotificationTemplate: string
{
    case OrderCreatedEmail = 'order_created_email';
    case OrderCreatedTelegram = 'order_created_telegram';
    case OrderAssignedPush = 'order_assigned_push';
    case OrderAssignedTelegram = 'order_assigned_telegram';
    case OrderPickedUpEmail = 'order_picked_up_email';
    case OrderPickedUpTelegram = 'order_picked_up_telegram';
    case OrderDeliveredEmail = 'order_delivered_email';
    case OrderDeliveredPush = 'order_delivered_push';
    case OrderDeliveredTelegram = 'order_delivered_telegram';
    case OrderFailedEmail = 'order_failed_email';
    case OrderFailedTelegram = 'order_failed_telegram';
    case OrderCancelledEmail = 'order_cancelled_email';
    case OrderCancelledPush = 'order_cancelled_push';
    case OrderCancelledTelegram = 'order_cancelled_telegram';

    /**
     * Тип события, к которому относится шаблон.
     */
    public function eventType(): EventType
    {
        return match ($this) {
            self::OrderCreatedEmail, self::OrderCreatedTelegram => EventType::OrderCreated,
            self::OrderAssignedPush, self::OrderAssignedTelegram => EventType::OrderAssigned,
            self::OrderPickedUpEmail, self::OrderPickedUpTelegram => EventType::OrderPickedUp,
            self::OrderDeliveredEmail,
            self::OrderDeliveredPush,
            self::OrderDeliveredTelegram => EventType::OrderDelivered,
            self::OrderFailedEmail, self::OrderFailedTelegram => EventType::OrderFailed,
            self::OrderCancelledEmail,
            self::OrderCancelledPush,
            self::OrderCancelledTelegram => EventType::OrderCancelled,
        };
    }

    /**
     * Канал доставки шаблона.
     */
    public function channel(): NotificationChannel
    {
        return match ($this) {
            self::OrderCreatedEmail,
            self::OrderPickedUpEmail,
            self::OrderDeliveredEmail,
            self::OrderFailedEmail,
            self::OrderCancelledEmail => NotificationChannel::Email,
            self::OrderAssignedPush,
            self::OrderDeliveredPush,
            self::OrderCancelledPush => NotificationChannel::Push,
            self::OrderCreatedTelegram,
            self::OrderAssignedTelegram,
            self::OrderPickedUpTelegram,
            self::OrderDeliveredTelegram,
            self::OrderFailedTelegram,
            self::OrderCancelledTelegram => NotificationChannel::Telegram,
        };
    }

    /**
     * Ключ текстов шаблона в lang/{locale}/notifications.php.
     */
    public function langKey(): string
    {
        return 'notifications.templates.'.$this->value;
    }

    /**
     * Шаблон для пары «тип события — канал».
     *
     * @throws NotificationTemplateNotFound шаблон домена не предусмотрен
     */
    public static function for(EventType $eventType, NotificationChannel $channel): self
    {
        $template = self::find($eventType, $channel);

        if ($template === null) {
            throw NotificationTemplateNotFound::make($eventType, $channel);
        }

        return $template;
    }

    /**
     * Существует ли шаблон для пары «тип события — канал».
     */
    public static function has(EventType $eventType, NotificationChannel $channel): bool
    {
        return self::find($eventType, $channel) !== null;
    }

    private static function find(EventType $eventType, NotificationChannel $channel): ?self
    {
        foreach (self::cases() as $template) {
            if ($template->eventType() === $eventType && $template->channel() === $channel) {
                return $template;
            }
        }

        return null;
    }
}
