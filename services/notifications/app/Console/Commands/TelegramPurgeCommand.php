<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Очистка токенов привязки Telegram (FR-NTF-412, ЧТЗ 7, п. 8).
 *
 * Удаляются использованные и истёкшие строки старше срока хранения:
 * действующие токены и история привязок не затрагиваются. Срок берётся
 * из конфигурации, ключ `--days` переопределяет его для разового прогона.
 * Команда отдельная от `notifications:purge`, потому что у токенов привязки
 * свой срок хранения.
 *
 * Коды возврата: 0 — очистка выполнена; 2 — ошибка флага, конфигурации
 * или выполнения. В выводе только счётчик удалённых строк: персональных
 * данных нет (FR-OPS-202).
 */
final class TelegramPurgeCommand extends Command
{
    /**
     * Очистка выполнена.
     */
    public const EXIT_OK = 0;

    /**
     * Ошибка флага, конфигурации или выполнения.
     */
    public const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:telegram:purge
        {--days= : Срок хранения токенов привязки в днях; по умолчанию — из конфигурации}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Удаляет использованные и истёкшие токены привязки Telegram';

    public function handle(): int
    {
        try {
            $days = $this->days();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        }

        $cutoff = Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:sP');

        try {
            // Использованный токен удаляется по моменту использования,
            // неиспользованный — по моменту истечения: действующие строки
            // остаются, пока не истекут (ЧТЗ 5.2).
            $deleted = DB::table('telegram_link_tokens')
                ->where(static function ($query) use ($cutoff): void {
                    $query->whereNotNull('used_at')->where('used_at', '<', $cutoff);
                })
                ->orWhere(static function ($query) use ($cutoff): void {
                    $query->whereNull('used_at')->where('expires_at', '<', $cutoff);
                })
                ->delete();
        } catch (Throwable) {
            return $this->reportError('Очистка токенов привязки завершилась ошибкой.');
        }

        $this->render($days, $deleted);

        return self::EXIT_OK;
    }

    /**
     * Срок хранения из конфигурации и ключа `--days` (FR-OPS-211).
     *
     * @throws InvalidArgumentException значение не положительное целое число
     */
    private function days(): int
    {
        $days = $this->option('days');

        if ($days === null || $days === '') {
            $days = config('notifications.telegram.link_token_retention_days');
        }

        if (! is_numeric($days) || (int) $days < 1) {
            throw new InvalidArgumentException('Срок хранения токенов должен быть положительным целым числом.');
        }

        return (int) $days;
    }

    private function render(int $days, int $deleted): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [
                    'command' => $this->getName(),
                    'status' => 'ok',
                    'days' => $days,
                    'deleted' => $deleted,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $this->info(sprintf('%s: удалено токенов старше %d дн. — %d', $this->getName(), $days, $deleted));
    }

    private function reportError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['command' => $this->getName(), 'status' => 'error', 'error' => $message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
