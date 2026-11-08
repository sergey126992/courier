<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventType;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\Contracts\EventListener;
use App\Services\Events\Exceptions\EventDeliveryFailed;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Шина внутри монолита (FR-NTF-121…126, FR-NTF-201…206).
 *
 * Карта «тип события → список слушателей» регистрируется сервис-провайдером,
 * а не конфигурацией домена. Слушатели вызываются изолированно: исключение
 * одного не мешает остальным, но при отказе хотя бы одного событие считается
 * непринятым и публикатор повторит передачу.
 *
 * Публикация — доставка в процессе: этот режим остаётся транспортом
 * in-memory для тестов и локального пути M7. Потоки и группы в нём
 * моделируются очередями в памяти, чтобы контракт и результат потребления
 * проверялись без Redis.
 */
final class LocalEventBus implements EventBus
{
    /**
     * Код журнала при отказе слушателя.
     */
    private const LISTENER_FAILED_CODE = 'EVENT_LISTENER_FAILED';

    /**
     * Код журнала при отказе обработчика потребления.
     */
    private const CONSUMER_FAILED_CODE = 'EVENT_CONSUMER_FAILED';

    /**
     * @var array<string, list<EventListener>>
     */
    private array $listeners;

    /**
     * Очереди потоков режима in-memory: поток → сообщения в порядке добавления.
     *
     * @var array<string, list<array{id: string, event: EventEnvelope}>>
     */
    private array $queues = [];

    /**
     * Выданные и ещё не подтверждённые сообщения, ключ «поток|группа».
     *
     * @var array<string, list<array{id: string, event: EventEnvelope}>>
     */
    private array $pending = [];

    /**
     * @param array<string, list<EventListener>> $listeners карта «тип события → слушатели»
     */
    public function __construct(array $listeners = [])
    {
        $this->listeners = $listeners;
    }

    /**
     * Регистрирует слушателя типа события; порядок вызова — порядок регистрации.
     */
    public function register(EventType $type, EventListener $listener): void
    {
        $this->listeners[$type->value][] = $listener;
    }

    /**
     * Публикует событие слушателям в процессе.
     */
    public function publish(EventEnvelope $event): void
    {
        $this->dispatch($event);
    }

    /**
     * Совместимый метод локальной реализации: вызывает слушателей события.
     */
    public function dispatch(EventEnvelope $event): void
    {
        $failures = [];

        foreach ($this->listenersFor($event) as $listener) {
            try {
                $listener->handle($event);
            } catch (Throwable $exception) {
                $failures[] = $exception;

                // В журнал попадают только идентификаторы: payload может содержать
                // данные получателя, поэтому в контекст он не передаётся (FR-NTF-149).
                Log::error('Слушатель не принял событие.', [
                    'code' => self::LISTENER_FAILED_CODE,
                    'message_id' => $event->messageId,
                    'event_type' => $event->eventType->value,
                    'listener' => $listener::class,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($failures !== []) {
            throw EventDeliveryFailed::make($event->messageId, $failures);
        }
    }

    /**
     * Кладёт событие в очередь потока режима in-memory.
     *
     * Метод не входит в контракт: он нужен тестам и локальным сценариям,
     * где поток пополняется без внешнего посредника.
     */
    public function enqueue(string $stream, EventEnvelope $event, ?string $messageId = null): string
    {
        $id = $messageId ?? $event->messageId;
        $this->queues[$stream][] = ['id' => $id, 'event' => $event];

        return $id;
    }

    /**
     * Читает пачку из очереди потока и вызывает обработчик по каждому сообщению.
     */
    public function consume(string $stream, string $group, string $consumer, int $limit, callable $handler): ConsumeResult
    {
        $this->assertPositiveLimit($limit);

        $queue = $this->queues[$stream] ?? [];
        $batch = array_splice($queue, 0, $limit);
        $this->queues[$stream] = $queue;

        return $this->deliver($batch, $stream, $group, $handler);
    }

    /**
     * Подтверждает сообщение: из pending оно больше не выдаётся.
     */
    public function ack(string $stream, string $group, string $messageId): void
    {
        $key = $this->pendingKey($stream, $group);

        $this->pending[$key] = array_values(array_filter(
            $this->pending[$key] ?? [],
            static fn (array $item): bool => $item['id'] !== $messageId,
        ));
    }

    /**
     * Возвращает в обработку неподтверждённые сообщения.
     *
     * В памяти задержка не моделируется: сообщение доступно сразу, поэтому
     * $idleSeconds принимается ради совместимости контракта.
     */
    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit, callable $handler): ConsumeResult
    {
        $this->assertPositiveLimit($limit);

        $key = $this->pendingKey($stream, $group);
        $pending = $this->pending[$key] ?? [];
        $batch = array_splice($pending, 0, $limit);
        $this->pending[$key] = $pending;

        return $this->deliver($batch, $stream, $group, $handler);
    }

    /**
     * Обрабатывает пачку: успешные подтверждаются, отказ оставляет сообщение в pending.
     *
     * @param list<array{id: string, event: EventEnvelope}> $batch
     * @param callable(EventEnvelope, string): void $handler
     */
    private function deliver(array $batch, string $stream, string $group, callable $handler): ConsumeResult
    {
        $messageIds = [];
        $processed = 0;
        $failed = 0;

        foreach ($batch as $item) {
            $messageIds[] = $item['id'];

            try {
                $handler($item['event'], $item['id']);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->pending[$this->pendingKey($stream, $group)][] = $item;

                Log::error('Обработчик не принял событие; сообщение оставлено в pending.', [
                    'code' => self::CONSUMER_FAILED_CODE,
                    'stream' => $stream,
                    'message_id' => $item['id'],
                    'exception' => $exception::class,
                ]);
            }
        }

        return new ConsumeResult(
            read: count($messageIds),
            processed: $processed,
            acknowledged: $processed,
            failed: $failed,
            messageIds: $messageIds,
        );
    }

    /**
     * @return list<EventListener>
     */
    private function listenersFor(EventEnvelope $event): array
    {
        return $this->listeners[$event->eventType->value] ?? [];
    }

    private function pendingKey(string $stream, string $group): string
    {
        return $stream.'|'.$group;
    }

    private function assertPositiveLimit(int $limit): void
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }
    }
}
