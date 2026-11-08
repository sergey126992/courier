<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationChannel;
use App\Logging\TechnicalLogger;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Telegram\TelegramUpdateResult;
use App\Services\Notifications\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Получение апдейтов бота Telegram и обработка `/start <token>` (FR-NTF-442).
 *
 * Команда живёт отдельным процессом: длительный long polling не задерживает
 * отправку уведомлений, у поллера отправки свой процесс (ЧТЗ 8.6). Таймаут
 * опроса, размер пачки и интервал между проходами берутся из конфигурации,
 * литеральных значений нет (FR-NTF-443). Оба режима используют один
 * обработчик апдейтов.
 *
 * Коды возврата: 0 — пачка обработана; 1 — есть ошибки обработки;
 * 2 — ошибка конфигурации или ключа. Вывод и журнал не содержат токена
 * бота, секрета и `chat_id` (FR-OPS-411, 412).
 */
final class TelegramPollCommand extends Command
{
    /**
     * Пачка обработана.
     */
    public const EXIT_OK = 0;

    /**
     * Есть ошибки обработки.
     */
    public const EXIT_FAILED = 1;

    /**
     * Ошибка конфигурации или ключа.
     */
    public const EXIT_INVALID = 2;

    /**
     * Флаг остановки цикла по сигналу.
     */
    private bool $running = true;

    /**
     * @var string
     */
    protected $signature = 'notifications:telegram:poll
        {--once : Обработать одну пачку и завершиться}
        {--limit= : Размер пачки апдейтов}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Принимает апдейты бота Telegram и обрабатывает /start <token>';

    public function handle(UpdateHandler $handler, TelegramBotClient $client, TechnicalLogger $logger): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            return self::EXIT_INVALID;
        }

        if (trim((string) config('notifications.telegram.bot_token')) === '') {
            return $this->reportConfigurationError('Не задан токен бота Telegram (notifications.telegram.bot_token).');
        }

        // Long polling и вебхук одновременно не работают: в режиме вебхука
        // апдейты принимает маршрут, а поллер остановился бы с конфликтом
        // (FR-NTF-442, ЧТЗ 8.6).
        $updatesMode = strtolower(trim((string) config('notifications.telegram.updates_mode', 'polling')));

        if ($updatesMode !== 'polling') {
            return $this->reportConfigurationError('Режим получения апдейтов не polling: notifications.telegram.updates_mode.');
        }

        $once = (bool) $this->option('once');
        $interval = max(1, (int) config('notifications.telegram.poll_interval_seconds', 1));
        $timeout = max(0, (int) config('notifications.telegram.poll_timeout_seconds', 0));

        if (! $once) {
            $this->installSignalHandlers();
        }

        /** @var array<string, int> $counters */
        $counters = [
            'processed' => 0,
            TelegramUpdateResult::Linked->value => 0,
            TelegramUpdateResult::Prompted->value => 0,
            TelegramUpdateResult::Rejected->value => 0,
            TelegramUpdateResult::Ignored->value => 0,
            'errors' => 0,
        ];

        $offset = null;

        do {
            $updates = $this->fetch($client, $logger, $counters, $offset, $limit, $timeout);

            foreach ($updates as $update) {
                $this->handleUpdate($handler, $logger, $counters, $update);

                $updateId = $this->updateId($update);

                if ($updateId !== null) {
                    $offset = $updateId + 1;
                }
            }

            if ($once || ! $this->running) {
                break;
            }

            sleep($interval);
        } while (true);

        $this->render($counters);

        return $counters['errors'] > 0 ? self::EXIT_FAILED : self::EXIT_OK;
    }

    /**
     * Один запрос апдейтов; ошибка транспорта считается ошибкой прохода.
     *
     * @param array<string, int> $counters
     * @return list<array<string, mixed>>
     */
    private function fetch(
        TelegramBotClient $client,
        TechnicalLogger $logger,
        array &$counters,
        ?int $offset,
        int $limit,
        int $timeout,
    ): array {
        try {
            return $client->getUpdates($offset, $limit, $timeout);
        } catch (Throwable) {
            $counters['errors']++;

            $logger->error('Получение апдейтов Telegram завершилось ошибкой.', [
                'channel' => NotificationChannel::Telegram->value,
                'status' => 'error',
            ]);

            return [];
        }
    }

    /**
     * Обработка одного апдейта с подсчётом итога.
     *
     * @param array<string, int> $counters
     * @param array<string, mixed> $update
     */
    private function handleUpdate(
        UpdateHandler $handler,
        TechnicalLogger $logger,
        array &$counters,
        array $update,
    ): void {
        try {
            $result = $handler->handle($update);
        } catch (Throwable) {
            $counters['errors']++;

            $logger->error('Обработка апдейта Telegram завершилась ошибкой.', [
                'channel' => NotificationChannel::Telegram->value,
                'status' => 'error',
            ]);

            return;
        }

        $counters['processed']++;
        $counters[$result->value]++;
    }

    /**
     * `update_id` апдейта: смещение следующего запроса считается по нему.
     *
     * @param array<string, mixed> $update
     */
    private function updateId(array $update): ?int
    {
        $id = $update['update_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Размер пачки: ключ `--limit` или значение из конфигурации.
     */
    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null || $option === '') {
            return max(1, (int) config('notifications.telegram.poll_batch_size', 1));
        }

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            $this->error($this->getName().': ключ --limit ожидает положительное целое число.');

            return null;
        }

        return (int) $option;
    }

    /**
     * Остановка по SIGTERM/SIGINT, если расширение pcntl доступно (FR-NTF-215).
     */
    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        call_user_func('pcntl_async_signals', true);

        $stop = function (): void {
            $this->running = false;
        };

        foreach (['SIGTERM', 'SIGINT'] as $signal) {
            if (defined($signal)) {
                call_user_func('pcntl_signal', constant($signal), $stop);
            }
        }
    }

    /**
     * Итог прохода: только счётчики, без персональных данных.
     *
     * @param array<string, int> $counters
     */
    private function render(array $counters): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['command' => $this->getName(), 'status' => 'ok'] + $counters,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $this->info(sprintf(
            '%s: обработано %d, привязано %d, подсказок %d, отклонено %d, пропущено %d, ошибок %d',
            $this->getName(),
            $counters['processed'],
            $counters[TelegramUpdateResult::Linked->value],
            $counters[TelegramUpdateResult::Prompted->value],
            $counters[TelegramUpdateResult::Rejected->value],
            $counters[TelegramUpdateResult::Ignored->value],
            $counters['errors'],
        ));
    }

    private function reportConfigurationError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['command' => $this->getName(), 'status' => 'error', 'error' => $message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_INVALID;
    }
}
