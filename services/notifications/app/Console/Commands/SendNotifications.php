<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\NotificationPoller;
use App\Services\Notifications\SenderResult;
use Illuminate\Console\Command;

/**
 * Поллер отправки уведомлений (FR-NTF-272, FR-NTF-274…277).
 *
 * Команда берёт пачку готовых к отправке уведомлений и обрабатывает её
 * построчно через `NotificationPoller`. Между проходами выдерживается
 * пауза `notifications.sender.poll_interval_seconds`; остановка по SIGTERM
 * и SIGINT завершает текущий проход и выходит из цикла (FR-NTF-215).
 *
 * Ключи: `--once` — один проход, `--limit=` — размер пачки, `--id=` —
 * адресная отправка одного уведомления, `--json` — машиночитаемый вывод.
 * Вывод не содержит адресов, телефонов, токенов и текстов сообщений.
 *
 * Коды возврата: 0 — проход выполнен; 1 — уведомление по `--id` не найдено;
 * 2 — некорректный ключ.
 */
final class SendNotifications extends Command
{
    /**
     * Проход выполнен.
     */
    public const EXIT_OK = 0;

    /**
     * Уведомление по `--id` не найдено.
     */
    public const EXIT_NOT_FOUND = 1;

    /**
     * Некорректное значение ключа.
     */
    public const EXIT_INVALID = 2;

    /**
     * Флаг остановки цикла по сигналу.
     */
    private bool $running = true;

    /**
     * @var string
     */
    protected $signature = 'notifications:send
        {--once : Выполнить один проход и завершиться}
        {--limit= : Размер пачки уведомлений}
        {--id= : Отправить одно уведомление по идентификатору}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Поллер отправки уведомлений с повторами по backoff';

    public function handle(NotificationPoller $poller): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            return self::EXIT_INVALID;
        }

        $id = $this->option('id');

        if ($id !== null && $id !== '') {
            if (! is_string($id) || ! ctype_digit($id)) {
                $this->error($this->getName().': ключ --id ожидает целое число.');

                return self::EXIT_INVALID;
            }

            $result = $poller->sendOne((int) $id);
            $this->render($result);

            return $result->missing ? self::EXIT_NOT_FOUND : self::EXIT_OK;
        }

        if ((bool) $this->option('once')) {
            $this->render($poller->run($limit));

            return self::EXIT_OK;
        }

        $this->installSignalHandlers();

        $interval = max(1, (int) config('notifications.sender.poll_interval_seconds'));

        while (true) {
            $result = $poller->run($limit);

            if ($result->processed > 0) {
                $this->render($result);
            }

            if (! $this->running) {
                break;
            }

            sleep($interval);
        }

        return self::EXIT_OK;
    }

    /**
     * Размер пачки: ключ `--limit` или значение из конфигурации.
     */
    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null || $option === '') {
            return max(1, (int) config('notifications.sender.batch_size', 1));
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
     */
    private function render(SenderResult $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'command' => $this->getName(),
                'status' => $result->missing ? 'not_found' : 'ok',
                'processed' => $result->processed,
                'sent' => $result->sent,
                'error' => $result->error,
                'failed' => $result->failed,
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($result->missing) {
            $this->warn($this->getName().': уведомление не найдено');

            return;
        }

        $this->info(sprintf(
            '%s: обработано %d, отправлено %d, ошибок %d, необработано %d',
            $this->getName(),
            $result->processed,
            $result->sent,
            $result->error,
            $result->failed,
        ));
    }
}
