<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventEnvelope;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\RecipientRole;
use App\Logging\TechnicalLogger;
use App\Models\Notification;

/**
 * Получатели уведомления по событию (FR-NTF-241, FR-NTF-242).
 *
 * Отправитель берётся из `payload.sender_id`, курьер — из `payload.courier_id`
 * события назначения. Событие `order.cancelled` приходит без назначения,
 * поэтому курьер определяется по собственной базе сервиса: самый поздний
 * журнал этого заказа с ролью `courier`. Если такого журнала нет, назначения
 * не было и push курьеру не создаётся (FR-NTF-242).
 */
final class RecipientResolver
{
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly TechnicalLogger $logger,
    ) {}

    /**
     * Получатели события в порядке матрицы; пустой список — уведомлять некого.
     *
     * @return list<Recipient>
     */
    public function recipients(EventEnvelope $event): array
    {
        if (! $this->policy->notifies($event->eventType)) {
            return [];
        }

        $courierId = $this->courierId($event);
        $hasAssignment = $courierId > 0;
        $recipients = [];

        foreach ($this->policy->recipients($event->eventType, $hasAssignment) as $role) {
            $userId = match ($role) {
                RecipientRole::Sender => $event->payloadInt('sender_id'),
                RecipientRole::Courier => $courierId,
            };

            if ($userId <= 0) {
                $this->logger->warning('Получатель уведомления не определён.', [
                    'correlation_id' => $event->correlationId,
                    'event_id' => $event->eventId(),
                    'error_code' => NotificationErrorCode::RECIPIENT_UNKNOWN,
                ]);

                continue;
            }

            $recipients[] = new Recipient($role, $userId);
        }

        return $recipients;
    }

    /**
     * Идентификатор курьера: из payload, иначе из журнала сервиса для отмены.
     */
    private function courierId(EventEnvelope $event): int
    {
        $fromPayload = $event->payloadInt('courier_id');

        if ($fromPayload > 0) {
            return $fromPayload;
        }

        $orderId = $event->payloadInt('order_id');

        if ($orderId <= 0) {
            return 0;
        }

        // В payload отмены назначения нет: курьера ищем в собственной базе
        // сервиса. Самый поздний журнал заказа с ролью courier — это курьер
        // последнего назначения; записей нет — назначения не было.
        $recipientId = Notification::query()
            ->where('order_id', $orderId)
            ->where('recipient_role', RecipientRole::Courier->value)
            ->orderByDesc('id')
            ->value('recipient_id');

        return is_numeric($recipientId) ? (int) $recipientId : 0;
    }
}
