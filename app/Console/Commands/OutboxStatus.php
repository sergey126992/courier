<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Метрики публикатора Outbox и код состояния для мониторинга (FR-OPS-103,
 * FR-OPS-104, FR-OPS-110).
 *
 * Коды возврата: 0 — в норме; 1 — превышен порог лага или есть необработанные
 * события; 2 — ошибка выполнения. Пороги задаются конфигурацией
 * (config/events.php), флаг --max-lag переопределяет порог лага. В выводе
 * только счётчики и моменты времени: персональных данных и секретов нет
 * (FR-OPS-108).
 */
final class OutboxStatus extends Command
{
    /**
     * Очередь в норме.
     */
    private const EXIT_OK = 0;

    /**
     * Порог превышен или есть необработанные события.
     */
    private const EXIT_DEGRADED = 1;

    /**
     * Ошибка выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * Значение status в норме.
     */
    private const STATUS_OK = 'ok';

    /**
     * Значение status при превышении порога.
     */
    private const STATUS_DEGRADED = 'degraded';

    /**
     * @var string
     */
    protected $signature = 'outbox:status
        {--json : Вывод метрик в JSON}
        {--max-lag= : Порог лага публикации в секундах}';

    /**
     * @var string
     */
    protected $description = 'Показывает метрики публикатора Outbox и состояние очереди';

    public function handle(): int
    {
        try {
            $maxLag = $this->maxLag();
            $metrics = $this->metrics($maxLag);
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        } catch (Throwable) {
            return $this->reportError('Сбор метрик Outbox завершился ошибкой.');
        }

        $this->render($metrics);

        return $metrics['status'] === self::STATUS_OK ? self::EXIT_OK : self::EXIT_DEGRADED;
    }

    /**
     * Порог лага из флага или из конфигурации (FR-OPS-104, FR-OPS-110).
     *
     * @throws InvalidArgumentException значение флага не является неотрицательным целым
     */
    private function maxLag(): int
    {
        $value = $this->option('max-lag');

        if ($value === null || $value === '') {
            return max(0, (int) config('events.max_publish_lag_seconds'));
        }

        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException('Флаг --max-lag должен быть неотрицательным целым числом.');
        }

        return (int) $value;
    }

    /**
     * Счётчики публикатора и состояние очереди.
     *
     * @return array{
     *     generated_at: string,
     *     status: string,
     *     transport: string,
     *     stream: string,
     *     pending_count: int,
     *     failed_count: int,
     *     oldest_pending_at: string|null,
     *     oldest_pending_age_seconds: int|null,
     *     publish_lag_seconds: int,
     *     published_last_hour: int,
     *     thresholds: array{max_lag_seconds: int, max_failed: int}
     * }
     */
    private function metrics(int $maxLag): array
    {
        $now = now();
        $maxAttempts = max(1, (int) config('events.publish_max_attempts'));
        $maxFailed = max(0, (int) config('events.max_unprocessed_count'));

        // Неопубликованные — все строки без published_at, включая строки,
        // которые уже исчерпали попытки и ждут ручного разбора (FR-NTF-108).
        $pending = OutboxMessage::query()->whereNull('published_at');

        $pendingCount = (clone $pending)->count();
        $failedCount = (clone $pending)->where('attempts', '>=', $maxAttempts)->count();

        $oldestValue = (clone $pending)->min('created_at');
        $oldestAt = is_string($oldestValue) ? Carbon::parse($oldestValue) : null;

        // Лаг публикации — возраст старейшей неопубликованной строки
        // (FR-OPS-104); без неопубликованных лаг равен нулю.
        $age = $oldestAt === null ? null : max(0, $now->getTimestamp() - $oldestAt->getTimestamp());
        $lag = $age ?? 0;

        $publishedLastHour = OutboxMessage::query()
            ->whereNotNull('published_at')
            // Сравнение с явным смещением: колонка timestamptz, а часовой пояс
            // приложения может отличаться от часового пояса сессии базы.
            ->whereRaw('published_at >= ?', [$now->copy()->subHour()->format('Y-m-d H:i:sP')])
            ->count();

        $degraded = $failedCount > $maxFailed || $lag > $maxLag;

        return [
            'generated_at' => $now->toIso8601String(),
            'status' => $degraded ? self::STATUS_DEGRADED : self::STATUS_OK,
            // Куда публикатор отправляет события: транспорт и поток заказов (FR-NTF-203).
            'transport' => (string) config('events.transport'),
            'stream' => (string) config('events.streams.orders'),
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
            'oldest_pending_at' => $oldestAt?->toIso8601String(),
            'oldest_pending_age_seconds' => $age,
            'publish_lag_seconds' => $lag,
            'published_last_hour' => $publishedLastHour,
            'thresholds' => [
                'max_lag_seconds' => $maxLag,
                'max_failed' => $maxFailed,
            ],
        ];
    }

    /**
     * @param array{
     *     generated_at: string,
     *     status: string,
     *     transport: string,
     *     stream: string,
     *     pending_count: int,
     *     failed_count: int,
     *     oldest_pending_at: string|null,
     *     oldest_pending_age_seconds: int|null,
     *     publish_lag_seconds: int,
     *     published_last_hour: int,
     *     thresholds: array{max_lag_seconds: int, max_failed: int}
     * } $metrics
     */
    private function render(array $metrics): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($metrics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info(sprintf('Состояние публикатора: %s.', $metrics['status']));
        $this->line(sprintf('Транспорт: %s, поток: %s.', $metrics['transport'], $metrics['stream']));
        $this->line(sprintf('Неопубликованных событий: %d.', $metrics['pending_count']));
        $this->line(sprintf('Необработанных событий: %d.', $metrics['failed_count']));
        $this->line(sprintf('Старейшее неопубликованное: %s.', $metrics['oldest_pending_at'] ?? '—'));
        $this->line(sprintf(
            'Возраст старейшего неопубликованного: %s.',
            $metrics['oldest_pending_age_seconds'] === null ? '—' : $metrics['oldest_pending_age_seconds'].' с',
        ));
        $this->line(sprintf(
            'Лаг публикации: %d с при пороге %d с.',
            $metrics['publish_lag_seconds'],
            $metrics['thresholds']['max_lag_seconds'],
        ));
        $this->line(sprintf('Опубликовано за последний час: %d.', $metrics['published_last_hour']));
    }

    private function reportError(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => now()->toIso8601String(),
                'status' => 'error',
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
