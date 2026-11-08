<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Очистка отозванных device-токенов по сроку хранения (ЧТЗ 7.3 п. 6,
 * FR-NTF-530).
 *
 * Удаляются только строки с заполненным `revoked_at`, который старше срока
 * хранения: активные токены не затрагиваются, пока устройство ими пользуется.
 * Срок берётся из `notifications.device_tokens.retention_days`, ключ `--days`
 * переопределяет его для разового прогона. Очистка — только команда и
 * расписание, триггеров и скрытого удаления нет.
 *
 * Коды возврата: 0 — очистка выполнена; 2 — ошибка флага, конфигурации или
 * выполнения. В выводе только срок и счётчик удалённых строк: полного токена
 * и персональных данных нет (FR-NTF-529, FR-NTF-531).
 */
final class DeviceTokensPurgeCommand extends Command
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
    protected $signature = 'notifications:device-tokens:purge
        {--days= : Срок хранения отозванных токенов в днях; по умолчанию — из конфигурации}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Удаляет отозванные device-токены старше срока хранения';

    public function handle(): int
    {
        try {
            $days = $this->days();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        }

        $cutoff = Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:sP');

        try {
            // Активный токен не отозван и очистке не подлежит (FR-NTF-530).
            $deleted = DB::table('device_tokens')
                ->whereNotNull('revoked_at')
                ->where('revoked_at', '<', $cutoff)
                ->delete();
        } catch (Throwable) {
            return $this->reportError('Очистка device-токенов завершилась ошибкой.');
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
            $days = config('notifications.device_tokens.retention_days');
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

        $this->info(sprintf('%s: удалено отозванных токенов старше %d дн. — %d', $this->getName(), $days, $deleted));
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
