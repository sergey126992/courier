<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Logging\TechnicalLogger;
use App\Models\OutboxMessage;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\EventContractValidator;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Публикатор Outbox сервиса: обратные события в поток `events.notifications` (FR-NTF-283).
 *
 * Работает по правилам публикатора монолита: выборка неопубликованных строк
 * под `FOR UPDATE SKIP LOCKED`, блокировка прохода, счётчик попыток и ряд
 * backoff из конфигурации. Отметка `published_at` ставится только после
 * успешного `XADD` — недоступный Redis оставляет событие в Outbox, а факт
 * отправки уведомления не отменяется (FR-NTF-286).
 *
 * Конверт проверяется по общей JSON Schema из каталога контрактов до `XADD`
 * (ЧТЗ 8.4): несовместимое событие в поток не попадает, строка остаётся
 * в Outbox с кодом причины.
 */
final readonly class OutboxPublisher
{
    /**
     * Ключ блокировки прохода: одновременно работает один публикатор (FR-NTF-283).
     */
    public const LOCK_KEY = 'notifications:outbox:publish';

    /**
     * Код причины в last_error при недоступном транспорте.
     */
    private const PUBLISH_FAILED_CODE = 'NOTIFICATION_OUTBOX_PUBLISH_FAILED';

    /**
     * @param list<int> $backoffSeconds ряд задержек в секундах, последний элемент повторяется
     *
     * @throws InvalidArgumentException некорректная конфигурация публикатора
     */
    public function __construct(
        private EventStream $stream,
        private EventContractValidator $validator,
        private int $batchSize,
        private int $maxAttempts,
        private array $backoffSeconds,
        private int $lockTtlSeconds,
        private string $streamName,
        private TechnicalLogger $logger,
    ) {
        if (trim($streamName) === '') {
            throw new InvalidArgumentException('Поток обратных событий сервиса не задан.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Размер пачки публикатора должен быть положительным.');
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Число попыток публикатора должно быть положительным.');
        }

        if ($backoffSeconds === []) {
            throw new InvalidArgumentException('Ряд backoff публикатора не должен быть пустым.');
        }

        if ($lockTtlSeconds < 1) {
            throw new InvalidArgumentException('TTL блокировки публикатора должен быть положительным.');
        }
    }

    /**
     * Один проход: пачка неопубликованных и готовых к отправке событий.
     */
    public function run(?int $limit = null): OutboxPublishResult
    {
        $batchSize = $limit ?? $this->batchSize;

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Ограничение пачки должно быть положительным.');
        }

        $lock = Cache::lock(self::LOCK_KEY, $this->lockTtlSeconds);

        if (! $lock->get()) {
            return OutboxPublishResult::locked();
        }

        try {
            // Блокировки строк держатся до конца прохода: параллельный
            // публикатор не возьмёт те же строки (FR-NTF-283).
            return DB::transaction(fn (): OutboxPublishResult => $this->process($batchSize));
        } finally {
            $lock->release();
        }
    }

    /**
     * Выборка пачки и передача событий по одному.
     */
    private function process(int $batchSize): OutboxPublishResult
    {
        $messages = OutboxMessage::query()
            ->whereNull('published_at')
            ->where('attempts', '<', $this->maxAttempts)
            // Сравнение с явным смещением: колонка timestamptz, а часовой
            // пояс приложения может отличаться от пояса сессии базы.
            ->whereRaw('available_at <= ?', [now()->utc()->format('Y-m-d H:i:sP')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->lock('for update skip locked')
            ->get();

        $published = 0;
        $failed = 0;

        foreach ($messages as $message) {
            if ($this->publishMessage($message)) {
                $published++;

                continue;
            }

            $failed++;
        }

        return new OutboxPublishResult($published, $failed);
    }

    /**
     * Проверяет конверт и передаёт событие; true — строка опубликована.
     */
    private function publishMessage(OutboxMessage $message): bool
    {
        $document = $this->document($message);

        $error = $this->validator->validate($message->event_type, $message->schema_version, $document);

        if ($error !== null) {
            // Контракт не найден или payload не соответствует схеме: повтор не
            // поможет, строка остаётся в Outbox с кодом причины (ЧТЗ 8.4).
            $this->logger->error('Обратное событие не прошло проверку контракта; строка остаётся в Outbox.', [
                'correlation_id' => $message->correlation_id,
                'event_id' => $message->message_id,
                'error_code' => $error,
            ]);

            $this->markUnprocessed($message, $error);

            return false;
        }

        if (! $this->stream->publish($this->streamName, $this->fields($document))) {
            // Недоступный транспорт: попытка учтена, backoff уже назначен,
            // статус уведомления не меняется (FR-NTF-286).
            $this->markFailed($message, self::PUBLISH_FAILED_CODE);

            return false;
        }

        // Отметка ставится только после принятия записи транспортом (FR-NTF-203).
        $this->markPublished($message);

        return true;
    }

    /**
     * Конверт обратного события в форме JSON Schema (ЧТЗ 8.3, 8.4).
     *
     * @return array<string, mixed>
     */
    private function document(OutboxMessage $message): array
    {
        $document = [
            'message_id' => $message->message_id,
            'event_type' => $message->event_type,
            'schema_version' => $message->schema_version,
            'occurred_at' => $message->created_at->format(DateTimeInterface::ATOM),
            'aggregate_type' => $message->aggregate_type,
            'aggregate_id' => (int) $message->aggregate_id,
            'payload' => $message->payload,
        ];

        if ($message->correlation_id !== null) {
            $document['correlation_id'] = $message->correlation_id;
        }

        return $document;
    }

    /**
     * Поля записи потока: конверт без добавления и переименования полей.
     *
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    private function fields(array $document): array
    {
        $fields = [];

        foreach ($document as $name => $value) {
            if ($value === null) {
                continue;
            }

            $fields[(string) $name] = is_array($value)
                ? (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : (string) $value;
        }

        return $fields;
    }

    /**
     * Отметка о том, что транспорт принял событие (FR-NTF-203).
     */
    private function markPublished(OutboxMessage $message): void
    {
        $message->forceFill(['published_at' => now()->utc()])->save();
    }

    /**
     * Сбой передачи: попытка учтена, задержка взята из ряда backoff.
     */
    private function markFailed(OutboxMessage $message, string $code): void
    {
        $attempts = $message->attempts + 1;

        $this->logger->warning('Событие не передано в транспорт; строка остаётся в Outbox.', [
            'correlation_id' => $message->correlation_id,
            'event_id' => $message->message_id,
            'error_code' => $code,
            'attempt' => $attempts,
        ]);

        $message->forceFill([
            'attempts' => $attempts,
            'last_error' => $code,
            'available_at' => now()->utc()->addSeconds($this->backoffDelay($attempts)),
        ])->save();
    }

    /**
     * Ошибка без повтора: строка сразу считается необработанной.
     */
    private function markUnprocessed(OutboxMessage $message, string $code): void
    {
        $message->forceFill([
            'attempts' => $this->maxAttempts,
            'last_error' => $code,
            'available_at' => now()->utc(),
        ])->save();
    }

    /**
     * Задержка перед следующей попыткой: номер попытки выбирает элемент ряда.
     */
    private function backoffDelay(int $attempts): int
    {
        $values = array_values($this->backoffSeconds);
        $index = min(max($attempts - 1, 0), count($values) - 1);

        return max(0, $values[$index]);
    }
}
