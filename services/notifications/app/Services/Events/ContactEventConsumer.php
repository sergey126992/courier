<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Services\Notifications\RecipientContactSync;

/**
 * Потребитель потока контактов `events.contacts` (FR-NTF-231…235).
 *
 * Контакты приходят отдельным потоком: конверт проверяется по схеме
 * `user-contact-changed.v1`, эффект — идемпотентный upsert `recipient_contacts`,
 * подтверждение — только после COMMIT. Порядок тот же, что у потока заказов,
 * поэтому используется общий EventStreamConsumer; отличается только эффект.
 * У потока свой получатель в группе, чтобы подтверждения одного потока
 * не влияли на другой (FR-NTF-207).
 */
final class ContactEventConsumer
{
    public function __construct(
        private readonly EventStreamConsumer $consumer,
        private readonly RecipientContactSync $contacts,
    ) {}

    /**
     * Один проход потребления потока контактов.
     */
    public function consume(string $streamName, string $consumerName, int $limit): ConsumeResult
    {
        return $this->consumer->consume(
            $streamName,
            $consumerName,
            $limit,
            fn (EventEnvelope $event): int => $this->contacts->process($streamName, $consumerName, $event),
        );
    }
}
