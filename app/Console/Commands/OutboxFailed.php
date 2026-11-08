<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order\OutboxMessage;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Неопубликованные и необработанные события Outbox (FR-OPS-109).
 *
 * Коды возврата: 0 — список построен, в том числе пустой; 2 — ошибка.
 * В выводе идентификаторы, тип события, число попыток и код последней ошибки:
 * payload не выводится, поэтому персональных данных и секретов нет
 * (FR-OPS-108).
 */
final class OutboxFailed extends Command
{
    /**
     * Список построен.
     */
    private const EXIT_OK = 0;

    /**
     * Ошибка выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * Размер списка по умолчанию.
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * @var string
     */
    protected $signature = 'outbox:failed
        {--limit= : Максимальное число событий в списке}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Показывает неопубликованные и необработанные события Outbox';

    public function handle(): int
    {
        try {
            $limit = $this->limit();
            $rows = $this->rows($limit);
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        } catch (Throwable) {
            return $this->reportError('Разбор событий Outbox завершился ошибкой.');
        }

        $this->render($rows);

        return self::EXIT_OK;
    }

    /**
     * Ограничение списка из флага: пустое значение — размер по умолчанию.
     *
     * @throws InvalidArgumentException значение не является положительным числом
     */
    private function limit(): int
    {
        $value = $this->option('limit');

        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Флаг --limit должен быть положительным целым числом.');
        }

        return (int) $value;
    }

    /**
     * Неопубликованные события: ожидающие повтор и уже необработанные.
     *
     * @return list<array{
     *     id: int,
     *     message_id: string,
     *     event_type: string,
     *     aggregate_type: string,
     *     aggregate_id: int,
     *     attempts: int,
     *     unprocessed: bool,
     *     last_error: string|null,
     *     created_at: string,
     *     available_at: string
     * }>
     */
    private function rows(int $limit): array
    {
        $maxAttempts = max(1, (int) config('events.publish_max_attempts'));

        $messages = OutboxMessage::query()
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($messages as $message) {
            $attempts = (int) $message->attempts;

            $rows[] = [
                'id' => (int) $message->getKey(),
                'message_id' => (string) $message->message_id,
                'event_type' => (string) $message->event_type,
                'aggregate_type' => (string) $message->aggregate_type,
                'aggregate_id' => (int) $message->aggregate_id,
                'attempts' => $attempts,
                'unprocessed' => $attempts >= $maxAttempts,
                'last_error' => $message->last_error,
                'created_at' => $message->created_at->toIso8601String(),
                'available_at' => $message->available_at->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{
     *     id: int,
     *     message_id: string,
     *     event_type: string,
     *     aggregate_type: string,
     *     aggregate_id: int,
     *     attempts: int,
     *     unprocessed: bool,
     *     last_error: string|null,
     *     created_at: string,
     *     available_at: string
     * }> $rows
     */
    private function render(array $rows): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['events' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($rows === []) {
            $this->info('Неопубликованных событий нет.');

            return;
        }

        $this->info(sprintf('Неопубликованных событий: %d.', count($rows)));

        $this->table(
            ['ID', 'Сообщение', 'Тип', 'Попытки', 'Необработано', 'Ошибка', 'Создано'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['message_id'],
                $row['event_type'],
                $row['attempts'],
                $row['unprocessed'] ? 'да' : 'нет',
                $row['last_error'] ?? '—',
                $row['created_at'],
            ], $rows),
        );
    }

    private function reportError(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'events' => [],
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
