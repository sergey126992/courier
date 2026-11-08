<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Notifications\NotificationErrorCode;
use App\Logging\TechnicalLogger;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\Exceptions\EnvelopeInvalid;
use Closure;
use Throwable;

/**
 * Проход по потоку событий: конверт, эффект, подтверждение (FR-NTF-204, FR-NTF-225, FR-NTF-265).
 *
 * Порядок одного сообщения строгий: проверить конверт по общей схеме,
 * выполнить эффект в транзакции Inbox и только после COMMIT подтвердить
 * сообщение. Исключение оставляет сообщение в pending: повторная выдача
 * выполнится после `pending_idle_seconds` и дедупликация отсечёт повтор.
 *
 * Недействительное сообщение (payload не по схеме, неизвестный тип или
 * версия) подтверждается без эффекта и не блокирует поток (FR-NTF-225):
 * причина сохраняется в `inbox_messages`, тела событий в журнал не попадают.
 * Сообщения, ожидающие дольше порога, забираются через XAUTOCLAIM, когда
 * новых сообщений нет.
 *
 * Класс не знает, какой поток читает: эффект передаётся замыканием, поэтому
 * один проход обслуживает и поток заказов, и поток контактов (FR-NTF-231).
 * Тела сообщений и персональные данные в журнал не пишутся (FR-OPS-202).
 */
final class EventStreamConsumer
{
    public function __construct(
        private readonly EventStream $stream,
        private readonly EnvelopeReader $reader,
        private readonly EventInbox $inbox,
        private readonly TechnicalLogger $logger,
    ) {}

    /**
     * Один проход потребления: пачка новых сообщений или возврат зависших.
     *
     * @param Closure(EventEnvelope): int $effect эффект обработчика; возвращает число созданных записей
     */
    public function consume(string $streamName, string $consumerName, int $limit, Closure $effect): ConsumeResult
    {
        $group = (string) config('notifications.transport.consumer_group');

        if (! $this->stream->ensureGroup($streamName, $group)) {
            return ConsumeResult::degraded();
        }

        $messages = $this->stream->read($streamName, $group, $consumerName, $limit);

        if ($messages === null) {
            return ConsumeResult::degraded();
        }

        if ($messages === []) {
            // Новых сообщений нет: возвращаем в работу те, что ждут дольше порога.
            $messages = $this->stream->reclaim(
                $streamName,
                $group,
                $consumerName,
                (int) config('notifications.transport.pending_idle_seconds'),
                $limit,
            );

            if ($messages === null) {
                return ConsumeResult::degraded();
            }
        }

        return $this->deliver($streamName, $group, $consumerName, $messages, $effect);
    }

    /**
     * Обрабатывает пачку: конверт, эффект, подтверждение.
     *
     * @param list<StreamMessage> $messages
     * @param Closure(EventEnvelope): int $effect
     */
    private function deliver(
        string $streamName,
        string $group,
        string $consumerName,
        array $messages,
        Closure $effect,
    ): ConsumeResult {
        $read = 0;
        $processed = 0;
        $acknowledged = 0;
        $failed = 0;
        $invalid = 0;
        $created = 0;

        foreach ($messages as $message) {
            $read++;

            try {
                $event = $this->reader->read($message);
            } catch (EnvelopeInvalid $exception) {
                $invalid++;

                $this->inbox->recordInvalid(
                    $streamName,
                    $consumerName,
                    $this->reader->invalidMessageId($message),
                    $exception->errorCode(),
                );

                if ($this->acknowledge($streamName, $group, $message->id)) {
                    $acknowledged++;
                } else {
                    $failed++;
                }

                continue;
            }

            try {
                // Пока идёт эффект, технический контекст записи содержит
                // correlation_id и event_id события (FR-OPS-201, FR-OPS-202):
                // вложенные записи потребителя связываются с исходным запросом.
                $created += $this->logger->run([
                    'correlation_id' => $event->correlationId,
                    'event_id' => $event->eventId(),
                ], static fn (): int => $effect($event));
            } catch (Throwable) {
                $failed++;

                // Сообщение остаётся в pending: повтор после pending_idle_seconds.
                $this->logger->error('Событие не обработано; сообщение оставлено в pending.', [
                    'correlation_id' => $event->correlationId,
                    'event_id' => $event->eventId(),
                    'error_code' => NotificationErrorCode::CONSUMER_FAILED,
                ]);

                continue;
            }

            if (! $this->acknowledge($streamName, $group, $message->id)) {
                $failed++;

                continue;
            }

            $processed++;
            $acknowledged++;
        }

        return new ConsumeResult(
            read: $read,
            processed: $processed,
            acknowledged: $acknowledged,
            failed: $failed,
            invalid: $invalid,
            created: $created,
        );
    }

    /**
     * Подтверждает сообщение; ошибка ack оставляет его в pending.
     */
    private function acknowledge(string $streamName, string $group, string $messageId): bool
    {
        return $this->stream->acknowledge($streamName, $group, $messageId);
    }
}
