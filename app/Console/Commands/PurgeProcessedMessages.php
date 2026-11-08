<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Events\ProcessedMessage;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Удаляет отметки Inbox старше срока хранения (FR-NTF-137).
 *
 * Срок хранения задаётся конфигурацией, а не литералом: по умолчанию берётся
 * events.inbox_retention_days, флаг --days переопределяет его для разового
 * прогона. Удаляются только записи старше срока; свежие остаются, иначе повтор
 * доставленного события дал бы второй эффект.
 *
 * Коды возврата: 0 — очистка выполнена; 2 — ошибка флага или выполнения.
 * В выводе только счётчики: персональных данных нет (FR-NTF-149).
 */
final class PurgeProcessedMessages extends Command
{
    /**
     * Очистка выполнена.
     */
    private const EXIT_OK = 0;

    /**
     * Ошибка флага или выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'processed-messages:purge
        {--days= : Срок хранения отметок в днях; по умолчанию — из конфигурации}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Удаляет отметки Inbox старше срока хранения';

    public function handle(): int
    {
        try {
            $days = $this->days();

            $deleted = ProcessedMessage::query()
                ->where('created_at', '<', now()->subDays($days))
                ->delete();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        } catch (Throwable) {
            return $this->reportError('Очистка отметок Inbox завершилась ошибкой.');
        }

        $this->render($deleted, $days);

        return self::EXIT_OK;
    }

    /**
     * Срок хранения из флага; пустое значение — значение конфигурации.
     *
     * @throws InvalidArgumentException значение не является положительным числом
     */
    private function days(): int
    {
        $value = $this->option('days');

        if ($value === null || $value === '') {
            $value = config('events.inbox_retention_days');
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Флаг --days должен быть положительным целым числом.');
        }

        return (int) $value;
    }

    private function render(int $deleted, int $days): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'deleted' => $deleted,
                'days' => $days,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info(sprintf('Удалено отметок Inbox старше %d дн.: %d.', $days, $deleted));
    }

    private function reportError(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'deleted' => 0,
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
