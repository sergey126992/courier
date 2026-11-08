<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\Exceptions\EventPayloadInvalid;
use App\Domain\Events\Exceptions\EventTypeUnknown;
use App\Models\Order\OutboxMessage;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\Contracts\EventPublisher;
use App\Services\Events\Exceptions\EventContractInvalid;
use App\Services\Events\Exceptions\EventContractUnknown;
use App\Services\Events\Exceptions\EventDeliveryFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Публикатор исходящих событий (FR-NTF-101…110).
 *
 * Работает только по зафиксированным строкам: проход не выполняется внутри
 * транзакции операции, поэтому незавершённая транзакция публикатору не видна
 * (FR-NTF-110). Пачка забирается под блокировкой строк FOR UPDATE SKIP LOCKED,
 * второй воркер те же строки не получит. Отметка published_at ставится только
 * после того, как событие принято транспортом (FR-NTF-106, FR-NTF-203):
 * опубликованное событие повторно не выбирается.
 */
final readonly class OutboxPublisher implements EventPublisher
{
    /**
     * Ключ блокировки прохода: одновременно работает один публикатор (FR-NTF-104).
     */
    public const LOCK_KEY = 'outbox:publish';

    /**
     * Код причины в last_error при сбое передачи слушателям.
     */
    private const FAILURE_CODE = EventDeliveryFailed::CODE;

    /**
     * @param list<int> $backoffSeconds ряд задержек в секундах, последний элемент повторяется
     *
     * @throws InvalidArgumentException некорректная конфигурация публикатора
     */
    public function __construct(
        private EventBus $bus,
        private OutboxEventEnvelopeFactory $envelopes,
        private JsonSchemaValidator $validator,
        private int $batchSize,
        private int $maxAttempts,
        private array $backoffSeconds,
        private int $lockTtlSeconds,
    ) {
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
     * Публикует одно событие в транспорт шины; повтор и состояние строки — забота run().
     *
     * Контракт EventBus не знает, слушатели это в процессе или внешний
     * посредник: published_at ставится только после успешного возврата (FR-NTF-203).
     * Payload сверяется со схемой контракта до передачи транспорту (FR-NTF-225):
     * несовпадение не доходит до потока и не теряет строку Outbox.
     *
     * @throws EventContractUnknown схема нужной версии не найдена
     * @throws EventContractInvalid payload не соответствует схеме
     */
    public function publish(EventEnvelope $event): void
    {
        $this->validator->validateEvent(
            $event->eventType->value,
            $event->schemaVersion->value,
            $event->toArray(),
        );

        $this->bus->publish($event);
    }

    /**
     * Один проход: пачка неопубликованных и готовых к отправке событий.
     *
     * Занятая блокировка — не ошибка: проход возвращает пустой результат, и
     * команда завершается успешно (FR-NTF-104).
     *
     * @throws InvalidArgumentException некорректное ограничение пачки
     */
    public function run(?int $limit = null): PublishResult
    {
        $batchSize = $limit ?? $this->batchSize;

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Ограничение пачки должно быть положительным.');
        }

        $lock = Cache::lock(self::LOCK_KEY, $this->lockTtlSeconds);

        if (! $lock->get()) {
            return PublishResult::locked();
        }

        try {
            // Транзакция держит блокировки строк на время передачи событий.
            // Слушатели выполняют свои эффекты в собственной транзакции-сейве,
            // поэтому отказ одного откатывает только его и не рвёт пачку.
            return DB::transaction(fn (): PublishResult => $this->process($batchSize));
        } finally {
            $lock->release();
        }
    }

    /**
     * Выборка пачки и передача событий по одному.
     */
    private function process(int $batchSize): PublishResult
    {
        $now = now();

        $messages = OutboxMessage::query()
            ->whereNull('published_at')
            ->where('attempts', '<', $this->maxAttempts)
            // Сравнение с явным смещением: колонка timestamptz, а часовой пояс
            // приложения может отличаться от часового пояса сессии базы.
            ->whereRaw('available_at <= ?', [$now->format('Y-m-d H:i:sP')])
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

        return new PublishResult($published, $failed);
    }

    /**
     * Передаёт событие одной строки; true — событие считается опубликованным.
     */
    private function publishMessage(OutboxMessage $message): bool
    {
        try {
            $envelope = $this->envelopes->fromMessage($message);
        } catch (EventTypeUnknown $exception) {
            // Бизнес-ошибка: повтора нет, событие помечается опубликованным (FR-NTF-123).
            Log::warning('Событие неизвестного типа помечено опубликованным.', [
                'code' => $exception->errorCode(),
                'message_id' => $message->message_id,
                'event_type' => $message->event_type,
            ]);

            $this->markPublished($message);

            return true;
        } catch (EventPayloadInvalid $exception) {
            // Программная ошибка: повтор не поможет, строка ждёт ручного разбора.
            Log::error('Событие с некорректным payload не передано.', [
                'code' => $exception->errorCode(),
                'message_id' => $message->message_id,
                'event_type' => $message->event_type,
            ]);

            $this->markUnprocessed($message, $exception->errorCode());

            return false;
        }

        try {
            DB::transaction(function () use ($envelope): void {
                $this->publish($envelope);
            });

            // Отметка ставится только после успешного принятия транспортом
            // (FR-NTF-203): недоступный Redis оставляет строку в Outbox.
            $this->markPublished($message);

            return true;
        } catch (EventContractUnknown $exception) {
            // Контракт события неизвестен: повтора нет, строка не теряется —
            // она остаётся в Outbox с кодом причины (ЧТЗ 8.4, 8.5).
            Log::warning('Схема контракта события не найдена; строка остаётся в Outbox.', [
                'code' => $exception->errorCode(),
                'message_id' => $message->message_id,
                'event_type' => $message->event_type,
                'schema_version' => $exception->schemaVersion(),
            ]);

            $this->markUnprocessed($message, $exception->errorCode());

            return false;
        } catch (EventContractInvalid $exception) {
            // Payload не соответствует схеме: значения payload в журнал не
            // попадают, только ключевое слово и путь поля (FR-NTF-235).
            Log::error('Payload события не прошёл проверку схемы; строка остаётся в Outbox.', [
                'code' => $exception->errorCode(),
                'message_id' => $message->message_id,
                'event_type' => $message->event_type,
                'schema_version' => $exception->schemaVersion(),
                'keyword' => $exception->keyword(),
                'pointer' => $exception->pointer(),
            ]);

            $this->markUnprocessed($message, $exception->errorCode());

            return false;
        } catch (Throwable $exception) {
            // Инфраструктурная ошибка: попытка учтена, backoff уже назначен,
            // строка ждёт следующего прохода и догоняется после восстановления
            // транспорта (FR-NTF-203, FR-NTF-210).
            Log::warning('Событие не передано в транспорт; строка остаётся в Outbox.', [
                'code' => self::FAILURE_CODE,
                'message_id' => $message->message_id,
                'event_type' => $message->event_type,
                'attempts' => $message->attempts + 1,
                'exception' => $exception::class,
            ]);

            $this->markFailed($message, self::FAILURE_CODE);

            return false;
        }
    }

    /**
     * Отметка о том, что транспорт принял событие (FR-NTF-106, FR-NTF-203).
     */
    private function markPublished(OutboxMessage $message): void
    {
        $message->forceFill(['published_at' => now()])->save();
    }

    /**
     * Сбой передачи: попытка учтена, задержка взята из ряда backoff (FR-NTF-107).
     */
    private function markFailed(OutboxMessage $message, string $code): void
    {
        $attempts = $message->attempts + 1;

        $message->forceFill([
            'attempts' => $attempts,
            'last_error' => $code,
            'available_at' => now()->addSeconds($this->backoffDelay($attempts)),
        ])->save();
    }

    /**
     * Ошибка без повтора: строка сразу считается необработанной (FR-NTF-108).
     */
    private function markUnprocessed(OutboxMessage $message, string $code): void
    {
        $message->forceFill([
            'attempts' => $this->maxAttempts,
            'last_error' => $code,
            'available_at' => now(),
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
