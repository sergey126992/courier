<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationStatus;
use App\Logging\TechnicalLogger;
use App\Metrics\MetricsFormat;
use App\Metrics\MetricsStore;
use Illuminate\Support\Facades\DB;

/**
 * Счётчики журнала уведомлений по каналам (FR-OPS-208).
 *
 * Считаются отправленные (`sent`), необработанные (`failed`) и повторённые
 * (попыток больше одной) уведомления. Значения пишутся в журнал сервиса
 * структурированной записью после пачки отправки: отдельных дашбордов и
 * общего сборщика метрик на этапе M11 нет (M13).
 *
 * Событийные счётчики M12 (FR-OPS-413, FR-OPS-418) ведутся в памяти
 * процесса и инкрементируются в момент события: срабатывания fallback по
 * каналам, отложенные тихими часами и ошибки Telegram по коду ответа.
 * Агрегат читается методами `fallbacks()`, `deferred()`, `telegramErrors()`.
 *
 * Счётчики отправки M13 (FR-OPS-502, FR-OPS-503) инкрементируются в процессе
 * и не требуют запроса к базе: `sent`/`failed`/`retried` и наблюдения
 * гистограммы сбрасываются в общий кэш методом `flushToCache()`, поэтому
 * `/metrics` читает готовое значение и не пересчитывает журнал (FR-OPS-508).
 *
 * Запросы агрегируют только счётчики: адреса, тексты писем и идентификаторы
 * получателей в журнал и в отчёт не попадают (FR-OPS-202).
 */
final class NotificationCounters
{
    /**
     * Состояние счётчика «отправлено».
     */
    public const SENT = 'sent';

    /**
     * Состояние счётчика «необработано».
     */
    public const FAILED = 'failed';

    /**
     * Состояние счётчика «повторено».
     */
    public const RETRIED = 'retried';

    /**
     * Событие «канал заменён следующим» (FR-OPS-413): счётчик срабатываний
     * fallback по каналам. Значение статуса строки счётчика.
     */
    public const FALLBACK = 'fallback';

    /**
     * Событие «отправка отложена тихими часами» (FR-OPS-413).
     */
    public const DEFERRED = 'deferred';

    /**
     * Канал события ошибки Telegram: код ответа хранится в поле `status`.
     */
    public const TELEGRAM_CHANNEL = 'telegram';

    /**
     * Отправленные уведомления по каналам до сброса в кэш (FR-OPS-502).
     *
     * @var array<string, int>
     */
    private array $sent = [];

    /**
     * Окончательные отказы по каналам до сброса в кэш.
     *
     * @var array<string, int>
     */
    private array $failed = [];

    /**
     * Повторённые уведомления по каналам до сброса в кэш.
     *
     * @var array<string, int>
     */
    private array $retried = [];

    /**
     * Наблюдения гистограммы длительности по каналам.
     *
     * @var array<string, array{buckets: array<int|string, int>, sum: float, count: int}>
     */
    private array $durations = [];

    /**
     * @param list<float> $histogramBuckets границы гистограммы из конфигурации
     */
    public function __construct(
        private readonly MetricsStore $store,
        private readonly TechnicalLogger $logger,
        private readonly array $histogramBuckets = [],
    ) {}

    /**
     * Срабатывания fallback по каналам: канал, который был заменён.
     *
     * @var array<string, int>
     */
    private array $fallbacks = [];

    /**
     * Отложенные тихими часами уведомления по каналам.
     *
     * @var array<string, int>
     */
    private array $deferred = [];

    /**
     * Ошибки Telegram по коду ответа (`400`, `403`, `429`, `5xx`, `timeout`).
     *
     * @var array<string, int>
     */
    private array $telegramErrors = [];

    /**
     * Счётчики по каналам и состояниям.
     *
     * @return list<array{channel: string, status: string, count: int}>
     */
    public function byChannel(): array
    {
        $counters = [];

        $rows = DB::table('notifications')
            ->select('channel', 'status', DB::raw('count(*) as aggregate'))
            ->whereIn('status', [NotificationStatus::Sent->value, NotificationStatus::Failed->value])
            ->groupBy('channel', 'status')
            ->orderBy('channel')
            ->get();

        foreach ($rows as $row) {
            $counters[] = [
                'channel' => (string) $row->channel,
                'status' => (string) $row->status,
                'count' => (int) $row->aggregate,
            ];
        }

        $retried = DB::table('notifications')
            ->select('channel', DB::raw('count(*) as aggregate'))
            ->where('attempts', '>', 1)
            ->groupBy('channel')
            ->orderBy('channel')
            ->get();

        foreach ($retried as $row) {
            $counters[] = [
                'channel' => (string) $row->channel,
                'status' => self::RETRIED,
                'count' => (int) $row->aggregate,
            ];
        }

        return $counters;
    }

    /**
     * Уведомление отправлено по каналу (FR-OPS-502).
     */
    public function recordSent(string $channel): void
    {
        $this->sent[$channel] = ($this->sent[$channel] ?? 0) + 1;
    }

    /**
     * Уведомление окончательно не отправлено по каналу (FR-OPS-502).
     */
    public function recordFailed(string $channel): void
    {
        $this->failed[$channel] = ($this->failed[$channel] ?? 0) + 1;
    }

    /**
     * Попытка отправки была повторной (FR-OPS-502).
     */
    public function recordRetried(string $channel): void
    {
        $this->retried[$channel] = ($this->retried[$channel] ?? 0) + 1;
    }

    /**
     * Фактическое время попытки отправки в наблюдение гистограммы (FR-OPS-503).
     *
     * Границы берутся из конфигурации: в коде литеральных границ нет.
     * Счётчики границ накопительные, как требует формат Prometheus.
     */
    public function observeDuration(string $channel, float $seconds): void
    {
        $entry = $this->durations[$channel] ?? ['buckets' => [], 'sum' => 0.0, 'count' => 0];

        foreach ($this->histogramBuckets as $bound) {
            $le = MetricsFormat::float($bound);
            $entry['buckets'][$le] = ($entry['buckets'][$le] ?? 0) + ($seconds <= $bound ? 1 : 0);
        }

        $entry['sum'] += max(0.0, $seconds);
        $entry['count']++;

        $this->durations[$channel] = $entry;
    }

    /**
     * Сбрасывает накопленные значения счётчиков и гистограммы в общий кэш.
     *
     * Кэш недоступен — значения остаются в памяти процесса и будут записаны
     * следующим проходом; сервис при этом не падает (FR-OPS-512).
     */
    public function flushToCache(): bool
    {
        if ($this->sent === [] && $this->failed === [] && $this->retried === [] && $this->durations === []) {
            return true;
        }

        $deltas = [
            self::SENT => $this->sent,
            self::FAILED => $this->failed,
            self::RETRIED => $this->retried,
            'durations' => $this->durations,
            'generated_at' => now()->utc()->toIso8601String(),
        ];

        $written = $this->store->updateCounters(
            static fn (array $current): array => self::merge($current, $deltas),
        );

        if (! $written) {
            $this->logger->warning('Счётчики метрик не записаны в кэш.', [
                'status' => 'degraded',
                'error_code' => 'METRICS_UNAVAILABLE',
            ]);

            return false;
        }

        $this->sent = [];
        $this->failed = [];
        $this->retried = [];
        $this->durations = [];

        return true;
    }

    /**
     * Срабатывание fallback: канал `fromChannel` заменён следующим (FR-OPS-413).
     *
     * Инкремент выполняется в момент события, агрегат читается методом
     * `fallbacks()` в том же процессе. База не нужна: отложенная строка
     * следующего канала ещё не отправлена.
     */
    public function recordFallback(string $fromChannel): void
    {
        $this->fallbacks[$fromChannel] = ($this->fallbacks[$fromChannel] ?? 0) + 1;
    }

    /**
     * Отправка канала отложена окном тихих часов (FR-OPS-413, FR-OPS-418).
     */
    public function recordDeferred(string $channel): void
    {
        $this->deferred[$channel] = ($this->deferred[$channel] ?? 0) + 1;
    }

    /**
     * Ошибка Telegram по коду ответа (`400`, `403`, `429`, `5xx`, `timeout`).
     */
    public function recordTelegramError(string $code): void
    {
        $this->telegramErrors[$code] = ($this->telegramErrors[$code] ?? 0) + 1;
    }

    /**
     * Агрегат срабатываний fallback по каналам.
     *
     * @return list<array{channel: string, status: string, count: int}>
     */
    public function fallbacks(): array
    {
        return $this->rows($this->fallbacks, self::FALLBACK);
    }

    /**
     * Агрегат отложенных тихими часами уведомлений по каналам.
     *
     * @return list<array{channel: string, status: string, count: int}>
     */
    public function deferred(): array
    {
        return $this->rows($this->deferred, self::DEFERRED);
    }

    /**
     * Агрегат ошибок Telegram по коду ответа.
     *
     * @return list<array{channel: string, status: string, count: int}>
     */
    public function telegramErrors(): array
    {
        ksort($this->telegramErrors);

        $rows = [];

        foreach ($this->telegramErrors as $code => $count) {
            // Канал один — Telegram, код ответа хранится в поле `status`.
            $rows[] = [
                'channel' => self::TELEGRAM_CHANNEL,
                'status' => (string) $code,
                'count' => $count,
            ];
        }

        return $rows;
    }

    /**
     * Все событийные счётчики одной формой: канал, статус, значение.
     *
     * @return list<array{channel: string, status: string, count: int}>
     */
    public function events(): array
    {
        return array_merge($this->fallbacks(), $this->deferred(), $this->telegramErrors());
    }

    /**
     * Сбрасывает событийные счётчики процесса.
     */
    public function reset(): void
    {
        $this->fallbacks = [];
        $this->deferred = [];
        $this->telegramErrors = [];
    }

    /**
     * Слияние документа кэша с накопленными значениями процесса.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $deltas
     * @return array<string, mixed>
     */
    private static function merge(array $current, array $deltas): array
    {
        return [
            self::SENT => self::sum($current[self::SENT] ?? null, $deltas[self::SENT] ?? []),
            self::FAILED => self::sum($current[self::FAILED] ?? null, $deltas[self::FAILED] ?? []),
            self::RETRIED => self::sum($current[self::RETRIED] ?? null, $deltas[self::RETRIED] ?? []),
            'durations' => self::mergeDurations($current['durations'] ?? null, $deltas['durations'] ?? []),
            'generated_at' => (string) ($deltas['generated_at'] ?? ''),
        ];
    }

    /**
     * Сложение значений счётчика по каналам.
     *
     * @param array<string, int> $deltas
     * @return array<string, int>
     */
    private static function sum(mixed $current, array $deltas): array
    {
        $result = [];

        foreach (is_array($current) ? $current : [] as $channel => $count) {
            if (is_string($channel) && is_numeric($count)) {
                $result[$channel] = max(0, (int) $count);
            }
        }

        foreach ($deltas as $channel => $count) {
            $key = (string) $channel;
            $result[$key] = ($result[$key] ?? 0) + (int) $count;
        }

        ksort($result);

        return $result;
    }

    /**
     * Слияние наблюдений гистограммы по каналам и границам.
     *
     * @param array<string, array{buckets: array<int|string, int>, sum: float, count: int}> $deltas
     * @return array<string, array{buckets: array<int|string, int>, sum: float, count: int}>
     */
    private static function mergeDurations(mixed $current, array $deltas): array
    {
        $result = [];

        foreach (is_array($current) ? $current : [] as $channel => $entry) {
            if (is_string($channel) && is_array($entry)) {
                $result[$channel] = self::normalizeDuration($entry);
            }
        }

        foreach ($deltas as $channel => $entry) {
            $key = (string) $channel;
            $existing = $result[$key] ?? ['buckets' => [], 'sum' => 0.0, 'count' => 0];
            $delta = self::normalizeDuration(is_array($entry) ? $entry : []);

            foreach ($delta['buckets'] as $le => $count) {
                $existing['buckets'][$le] = ($existing['buckets'][$le] ?? 0) + $count;
            }

            $result[$key] = [
                'buckets' => $existing['buckets'],
                'sum' => $existing['sum'] + $delta['sum'],
                'count' => $existing['count'] + $delta['count'],
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * @param array<mixed> $entry
     * @return array{buckets: array<int|string, int>, sum: float, count: int}
     */
    private static function normalizeDuration(array $entry): array
    {
        $buckets = [];

        foreach (is_array($entry['buckets'] ?? null) ? $entry['buckets'] : [] as $le => $count) {
            // Числовые ключи PHP приводит к int: границу `1` читаем как `1`.
            if (is_numeric($count)) {
                $buckets[(string) $le] = max(0, (int) $count);
            }
        }

        return [
            'buckets' => $buckets,
            'sum' => is_numeric($entry['sum'] ?? null) ? (float) $entry['sum'] : 0.0,
            'count' => is_numeric($entry['count'] ?? null) ? (int) $entry['count'] : 0,
        ];
    }

    /**
     * Строки агрегата в одном порядке: по каналу, затем по коду.
     *
     * @param array<string, int> $counts
     * @return list<array{channel: string, status: string, count: int}>
     */
    private function rows(array $counts, string $status): array
    {
        ksort($counts);

        $rows = [];

        foreach ($counts as $channel => $count) {
            $rows[] = [
                'channel' => (string) $channel,
                'status' => $status,
                'count' => $count,
            ];
        }

        return $rows;
    }
}
