<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\DlqService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Очистка очереди необработанных по сроку хранения (ЧТЗ 8.6, FR-NTF-508).
 *
 * Ключи: `--days=`, `--json`. Срок берётся из `notifications.dlq.retention_days`,
 * флаг переопределяет его разовым прогоном. Журнал уведомлений не трогается:
 * запись DLQ переживает свой срок независимо от журнала. Автоматического
 * удаления «по кнопке» и удаления при повторе нет.
 *
 * Коды возврата: 0 — очистка выполнена; 2 — ошибка ключа, конфигурации
 * или выполнения.
 */
final class DlqPurge extends Command
{
    /**
     * Очистка выполнена.
     */
    private const EXIT_OK = 0;

    /**
     * Ошибка ключа, конфигурации или выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:dlq-purge
        {--days= : Срок хранения в днях; по умолчанию — из конфигурации}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Удаляет записи очереди необработанных старше срока хранения';

    public function handle(DlqService $dlq): int
    {
        try {
            $days = $this->days();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage(), 0, 0);
        }

        try {
            $deleted = $dlq->purgeExpired($days);
        } catch (Throwable) {
            return $this->reportError('Очистка очереди необработанных завершилась ошибкой.', $days, 0);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['deleted' => $deleted, 'retention_days' => $days],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->info(sprintf('Удалено записей старше %d дн.: %d.', $days, $deleted));
        }

        return self::EXIT_OK;
    }

    /**
     * Срок хранения из ключа или конфигурации.
     *
     * @throws InvalidArgumentException значение не положительное целое число
     */
    private function days(): int
    {
        $value = $this->option('days');

        if ($value === null || $value === '') {
            $value = config('notifications.dlq.retention_days');
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Срок хранения должен быть положительным целым числом дней.');
        }

        return (int) $value;
    }

    private function reportError(string $message, int $days, int $deleted): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['error' => $message, 'deleted' => $deleted, 'retention_days' => $days],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
