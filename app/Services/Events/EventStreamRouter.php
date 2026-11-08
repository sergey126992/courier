<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventType;

/**
 * Выбор потока Redis по типу события (FR-NTF-206, FR-NTF-207, FR-NTF-231).
 *
 * Заказы идут в общий поток заказов, контакты получателей — в отдельный поток:
 * персональные данные одного события не смешиваются с событиями заказа
 * (FR-NTF-232). Обратные события оповещений публикует сервис, поэтому в
 * монолите своих потоков у них нет и маршрут для них пока не задаётся —
 * они попадут в поток по умолчанию, когда появятся в реестре типов.
 *
 * Имена потоков приходят из конфигурации: литералов здесь нет (FR-OPS-211).
 */
final readonly class EventStreamRouter
{
    public function __construct(
        private string $orders,
        private string $notifications,
        private string $contacts,
    ) {}

    /**
     * Поток, в который публикуется событие этого типа.
     */
    public function streamFor(EventType $eventType): string
    {
        return match ($eventType) {
            EventType::UserContactChanged => $this->contacts,
            // Обратные события живут в собственном потоке (FR-NTF-207):
            // заказы и уведомления не смешиваются.
            EventType::NotificationSent, EventType::NotificationFailed => $this->notifications,
            default => $this->orders,
        };
    }

    /**
     * Поток обратных событий оповещений (FR-NTF-281): публикует сервис.
     */
    public function notificationsStream(): string
    {
        return $this->notifications;
    }

    /**
     * Поток событий заказов по умолчанию.
     */
    public function ordersStream(): string
    {
        return $this->orders;
    }

    /**
     * Поток контактов получателей (единственный поток с персональными данными).
     */
    public function contactsStream(): string
    {
        return $this->contacts;
    }
}
