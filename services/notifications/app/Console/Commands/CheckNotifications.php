<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationChannel;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Support\Contracts\SchemaDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Самопроверка сервиса при старте (FR-OPS-209).
 *
 * Проверяется конфигурация транспорта, отправки и доступа, читаемость
 * каталога общих JSON Schema и доступность базы. Некорректная конфигурация
 * останавливает процесс: команда вызывается в entrypoint и возвращает 1.
 *
 * Redis намеренно не проверяется: его недоступность — это `degraded`
 * в /health, а не остановка процесса (FR-OPS-204). В сообщениях нет
 * персональных данных, секретов и строк подключения (FR-OPS-202).
 *
 * Коды возврата: 0 — проверка пройдена; 1 — конфигурация некорректна.
 */
final class CheckNotifications extends Command
{
    /**
     * Проверка пройдена.
     */
    public const EXIT_OK = 0;

    /**
     * Конфигурация некорректна: процесс сервиса не должен стартовать.
     */
    public const EXIT_INVALID = 1;

    /**
     * @var string
     */
    protected $signature = 'notifications:check
        {--telegram : Проверить Bot API методом getMe (опционально)}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Проверяет конфигурацию сервиса, читаемость схем и доступность базы';

    public function __construct(private readonly SchemaDirectory $schemas)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $configuration = $this->configurationProblems();

        $checks = [
            'config' => $configuration === [] ? 'ok' : 'error',
            'contracts' => $this->schemas->isReadable() ? 'ok' : 'error',
            'database' => $this->databaseStatus(),
        ];

        $problems = array_merge(
            $configuration,
            $checks['contracts'] === 'ok' ? [] : ['Каталог общих JSON Schema не читается или пуст (notifications.contracts.path).'],
            $checks['database'] === 'ok' ? [] : ['База сервиса недоступна.'],
        );

        // Внешний канал в критичный путь не входит (FR-OPS-414): `getMe`
        // вызывается только по явному флагу, результат читается в выводе
        // и на `/health` не влияет. Токен в вывод не попадает (FR-OPS-415).
        if ((bool) $this->option('telegram')) {
            $checks['telegram'] = $this->telegramStatus();

            if ($checks['telegram'] === 'error') {
                $problems[] = 'API Telegram недоступен или отклонил getMe (флаг --telegram).';
            }
        }

        $ok = $problems === [];

        $this->render($checks, $problems, $ok);

        return $ok ? self::EXIT_OK : self::EXIT_INVALID;
    }

    /**
     * Проблемы конфигурации сервиса (FR-OPS-211).
     *
     * @return list<string>
     */
    private function configurationProblems(): array
    {
        $problems = [];

        if (trim((string) config('notifications.transport.driver')) === '') {
            $problems[] = 'Не задан транспорт событий (notifications.transport.driver).';
        }

        foreach (['orders', 'notifications', 'contacts'] as $stream) {
            if (trim((string) config('notifications.transport.streams.'.$stream)) === '') {
                $problems[] = 'Не задано имя потока: notifications.transport.streams.'.$stream.'.';
            }
        }

        if (trim((string) config('notifications.transport.consumer_group')) === '') {
            $problems[] = 'Не задана consumer group (notifications.transport.consumer_group).';
        }

        if ((int) config('notifications.transport.batch_size') < 1) {
            $problems[] = 'Размер пачки потребления должен быть положительным.';
        }

        if (trim((string) config('notifications.api.token')) === '') {
            $problems[] = 'Не задан сервисный токен (notifications.api.token).';
        }

        // Включённый канал Telegram требует токена и имени бота: иначе процесс
        // останавливается при старте с понятной причиной без секретов
        // (FR-NTF-441). Сами значения в сообщения не выводятся.
        $enabledChannels = array_map(
            static fn (mixed $channel): string => strtolower(trim((string) $channel)),
            (array) config('notifications.enabled_channels', []),
        );

        if (in_array(NotificationChannel::Telegram->value, $enabledChannels, true)) {
            if (trim((string) config('notifications.telegram.bot_token')) === '') {
                $problems[] = 'Канал Telegram включён, но не задан токен бота (notifications.telegram.bot_token).';
            }

            if (trim((string) config('notifications.telegram.bot_username')) === '') {
                $problems[] = 'Канал Telegram включён, но не задано имя бота (notifications.telegram.bot_username).';
            }

            if ((int) config('notifications.telegram.message_limit') < 1) {
                $problems[] = 'Лимит сообщения Telegram должен быть положительным (notifications.telegram.message_limit).';
            }
        }

        if ((int) config('notifications.sender.max_attempts') < 1) {
            $problems[] = 'Число попыток отправки должно быть положительным.';
        }

        if ((int) config('notifications.sender.timeout_seconds') < 1) {
            $problems[] = 'Таймаут отправки должен быть положительным.';
        }

        if (! $this->retryAfterCoversBatch()) {
            $problems[] = 'Пауза повтора отправки должна быть больше времени обработки пачки.';
        }

        foreach (['days', 'attempt_days', 'push_days', 'inbox_days', 'dedup_days'] as $retention) {
            if ((int) config('notifications.retention.'.$retention) < 1) {
                $problems[] = 'Срок хранения должен быть положительным: notifications.retention.'.$retention.'.';
            }
        }

        if (trim((string) config('notifications.retention.purge_cron')) === '') {
            $problems[] = 'Не задано расписание очистки (notifications.retention.purge_cron).';
        }

        $backoff = config('notifications.sender.backoff_seconds');

        if (! is_array($backoff) || $backoff === []) {
            $problems[] = 'Ряд задержек повтора пуст (notifications.sender.backoff_seconds).';
        }

        if ((int) config('notifications.health.lag_threshold_seconds') < 0) {
            $problems[] = 'Порог лага не может быть отрицательным.';
        }

        if ((int) config('notifications.health.pending_threshold') < 0) {
            $problems[] = 'Порог числа необработанных не может быть отрицательным.';
        }

        return $problems;
    }

    /**
     * Пауза повтора отправки строго больше времени обработки пачки (FR-OPS-205).
     *
     * Худшее время пачки — размер пачки, умноженный на таймаут одной попытки.
     */
    private function retryAfterCoversBatch(): bool
    {
        $batchSeconds = (int) config('notifications.sender.batch_size')
            * (int) config('notifications.sender.timeout_seconds');

        return (int) config('notifications.sender.retry_after_seconds') > $batchSeconds;
    }

    /**
     * Опциональная проверка Bot API методом `getMe` (FR-OPS-415).
     *
     * Без токена бота запрос не выполняется: состояние `skipped`. Любой
     * отказ Telegram — `error`, причина и токен в сообщения не выводятся.
     */
    private function telegramStatus(): string
    {
        if (trim((string) config('notifications.telegram.bot_token')) === '') {
            return 'skipped';
        }

        try {
            return app(TelegramBotClient::class)->getMe() === [] ? 'error' : 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    /**
     * Доступность базы сервиса.
     */
    private function databaseStatus(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    /**
     * Вывод результата проверки: только состояния и понятные причины.
     *
     * @param array<string, string> $checks
     * @param list<string> $problems
     */
    private function render(array $checks, array $problems, bool $ok): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'command' => $this->getName(),
                'status' => $ok ? 'ok' : 'error',
                'checks' => $checks,
                'problems' => $problems,
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($ok) {
            $this->info($this->getName().': проверка пройдена');

            return;
        }

        $this->error($this->getName().': конфигурация некорректна, сервис не будет запущен');

        foreach ($problems as $problem) {
            $this->line(' - '.$problem);
        }
    }
}
