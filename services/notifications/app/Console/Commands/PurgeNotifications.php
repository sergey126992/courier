<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Очистка журнала сервиса по срокам хранения (FR-NTF-267, ЧТЗ 7, п. 8).
 *
 * У каждой таблицы свой срок из конфигурации: `inbox_messages` и
 * `notification_dedup` — короткий (повтор события отсекается уникальным
 * индексом, старые ключи уже не нужны), `notifications` и
 * `notification_attempts` — длинный, `push_messages` — короткий.
 * Флаг `--days` переопределяет только срок журнала уведомлений разового
 * прогона, как в монолитной `notifications:purge`.
 *
 * Удаление не ломает ссылки: попытки удаляются раньше уведомлений
 * (у `notification_attempts` каскад на уведомление), строка push-журнала
 * переживает удаление уведомления с обнулённой ссылкой (`nullOnDelete`).
 * Частичный индекс Outbox и уникальные индексы не затрагиваются: строки
 * удаляются, а не переписываются.
 *
 * Коды возврата: 0 — очистка выполнена; 2 — ошибка флага, конфигурации
 * или выполнения. В выводе только счётчики: персональных данных нет
 * (FR-OPS-202).
 */
final class PurgeNotifications extends Command
{
    /**
     * Очистка выполнена.
     */
    private const EXIT_OK = 0;

    /**
     * Ошибка флага, конфигурации или выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:purge
        {--days= : Срок хранения журнала уведомлений в днях; по умолчанию — из конфигурации}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Удаляет записи журнала сервиса старше сроков хранения';

    public function handle(): int
    {
        try {
            $retention = $this->retention();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        }

        try {
            // Порядок — от зависимых строк к независимым: попытки и push-журнал
            // раньше уведомлений, чтобы срок каждой таблицы соблюдался сам по
            // себе, а каскад и обнуление ссылки лишь дочищали удалённое.
            $result = [
                'inbox_messages' => $this->purge('inbox_messages', $retention['inbox_days']),
                'notification_dedup' => $this->purge('notification_dedup', $retention['dedup_days']),
                'notification_attempts' => $this->purge('notification_attempts', $retention['attempt_days']),
                'push_messages' => $this->purge('push_messages', $retention['push_days']),
                'notifications' => $this->purge('notifications', $retention['days']),
            ];
        } catch (Throwable) {
            return $this->reportError('Очистка журнала сервиса завершилась ошибкой.');
        }

        $this->render($result, $retention);

        return self::EXIT_OK;
    }

    /**
     * Число удалённых строк старше срока хранения таблицы.
     */
    private function purge(string $table, int $days): int
    {
        return DB::table($table)
            ->where('created_at', '<', now()->utc()->subDays($days))
            ->delete();
    }

    /**
     * Сроки хранения из конфигурации и флага `--days` (FR-OPS-211).
     *
     * @return array{days: int, attempt_days: int, push_days: int, inbox_days: int, dedup_days: int}
     *
     * @throws InvalidArgumentException значение срока не положительное целое число
     */
    private function retention(): array
    {
        $days = $this->option('days');

        if ($days === null || $days === '') {
            $days = config('notifications.retention.days');
        }

        return [
            'days' => $this->positiveDays($days, 'Флаг --days должен быть положительным целым числом.'),
            'attempt_days' => $this->configDays('notifications.retention.attempt_days'),
            'push_days' => $this->configDays('notifications.retention.push_days'),
            'inbox_days' => $this->configDays('notifications.retention.inbox_days'),
            'dedup_days' => $this->configDays('notifications.retention.dedup_days'),
        ];
    }

    /**
     * @throws InvalidArgumentException значение конфигурации не положительное число
     */
    private function configDays(string $key): int
    {
        return $this->positiveDays(
            config($key),
            'Срок хранения из конфигурации должен быть положительным целым числом.',
        );
    }

    /**
     * @throws InvalidArgumentException значение не положительное целое число
     */
    private function positiveDays(mixed $value, string $message): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException($message);
        }

        return (int) $value;
    }

    /**
     * Вывод результата: только счётчики и сроки.
     *
     * @param array{inbox_messages: int, notification_dedup: int, notification_attempts: int, push_messages: int, notifications: int} $result
     * @param array{days: int, attempt_days: int, push_days: int, inbox_days: int, dedup_days: int} $retention
     */
    private function render(array $result, array $retention): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result + $retention,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $this->info(sprintf(
            'Удалено: уведомлений старше %d дн. — %d, попыток старше %d дн. — %d, '
            .'записей push-журнала старше %d дн. — %d, сообщений Inbox старше %d дн. — %d, '
            .'ключей дедупликации старше %d дн. — %d.',
            $retention['days'],
            $result['notifications'],
            $retention['attempt_days'],
            $result['notification_attempts'],
            $retention['push_days'],
            $result['push_messages'],
            $retention['inbox_days'],
            $result['inbox_messages'],
            $retention['dedup_days'],
            $result['notification_dedup'],
        ));
    }

    private function reportError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['error' => $message, 'notifications' => 0],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
