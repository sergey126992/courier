<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Exceptions\DlqEntryNotFound;
use App\Domain\Notifications\Exceptions\DlqEntryNotReplayable;
use App\Models\DlqEntry;
use App\Services\Notifications\DlqService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Повтор записи очереди необработанных из консоли (ЧТЗ 8.6, FR-NTF-506).
 *
 * Ключи: `{id}`, `--json`. Повтор возвращает ту же строку журнала в `error`
 * и ставит её в очередь поллера; новой записи DLQ и нового уведомления нет.
 *
 * Коды возврата: 0 — повтор поставлен; 1 — записи нет или она уже разобрана
 * (или уже в повторе); 2 — ошибка ключа или выполнения.
 */
final class DlqReplay extends Command
{
    /**
     * Повтор поставлен.
     */
    private const EXIT_OK = 0;

    /**
     * Записи нет или повтор недопустим.
     */
    private const EXIT_NOT_REPLAYABLE = 1;

    /**
     * Ошибка ключа или выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:dlq-replay
        {id : Идентификатор записи очереди необработанных}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Ставит запись очереди необработанных на повтор';

    public function handle(DlqService $dlq): int
    {
        $id = $this->entryId();

        if ($id === null) {
            return $this->reportError('Идентификатор записи должен быть положительным целым числом.');
        }

        try {
            $entry = $dlq->replay($id);
        } catch (DlqEntryNotFound|DlqEntryNotReplayable $exception) {
            return $this->reportNotReplayable($exception->getMessage());
        } catch (Throwable) {
            return $this->reportError('Повтор записи завершился ошибкой.');
        }

        $this->render($entry);

        return self::EXIT_OK;
    }

    private function entryId(): ?int
    {
        $value = $this->argument('id');

        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DlqEntry $entry): array
    {
        return [
            'id' => (int) $entry->getKey(),
            'notification_id' => (int) $entry->notification_id,
            'channel' => (string) $entry->channel,
            'state' => $entry->state->value,
            'replay_count' => (int) $entry->replay_count,
            'last_replayed_at' => $entry->last_replayed_at?->toIso8601String(),
        ];
    }

    private function render(DlqEntry $entry): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $this->payload($entry),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $this->info(sprintf(
            'Запись %d (уведомление %d, канал %s) поставлена на повтор, повторов: %d.',
            (int) $entry->getKey(),
            (int) $entry->notification_id,
            (string) $entry->channel,
            (int) $entry->replay_count,
        ));
    }

    private function reportNotReplayable(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['error' => $message, 'replayed' => false],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_NOT_REPLAYABLE;
    }

    private function reportError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['error' => $message, 'replayed' => false],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
