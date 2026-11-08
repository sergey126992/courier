<?php

declare(strict_types=1);

namespace App\Metrics;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Снимок метрик сервиса: счётчики, гистограмма и «дорогие» gauge (FR-OPS-501…506).
 *
 * Снимок собирается из двух документов кэша: счётчики и гистограмму пишет
 * процесс отправки, gauge — задача расписания. Форма снимка одна и для
 * экспортёра `/metrics`, и для JSON-вывода команды `notifications:metrics-snapshot`,
 * поэтому значения панели и сборщика совпадают.
 *
 * Метки снимка — только из белого списка (FR-OPS-507): идентификаторов
 * получателя, заказа и уведомления здесь нет (FR-OPS-512).
 */
final class MetricsSnapshot
{
    /**
     * Снимок построен по всем источникам.
     */
    public const STATUS_OK = 'ok';

    /**
     * Часть источников недоступна: значения отданы частично (FR-OPS-512).
     */
    public const STATUS_DEGRADED = 'degraded';

    /**
     * Значения счётчиков по каналам: `sent`, `failed`, `retried`.
     */
    public const SENT = 'sent';

    /**
     * Значение счётчика окончательных отказов.
     */
    public const FAILED = 'failed';

    /**
     * Значение счётчика повторённых уведомлений.
     */
    public const RETRIED = 'retried';

    /**
     * @param array<string, array<string, int>> $counters значения по каналам
     * @param array<string, array{buckets: array<int|string, int>, sum: float, count: int}> $durations
     * @param list<array{channel: string, state: string, value: int}> $dlqDepth
     * @param list<array{stream: string, lag_seconds: int, pending: int}> $consumers
     * @param list<string> $degraded
     */
    public function __construct(
        public readonly array $counters,
        public readonly array $durations,
        public readonly array $dlqDepth,
        public readonly array $consumers,
        public readonly int $outboxOldestUnpublishedSeconds,
        public readonly array $degraded,
        public readonly Carbon $generatedAt,
    ) {}

    /**
     * Снимок из документов кэша.
     *
     * Отсутствие документа означает «значений ещё нет» и даёт нули, а не
     * деградацию: первый проход отправки или задачи расписания просто ещё
     * не случился. Деградацию задаёт только недоступность кэша (`null`).
     *
     * @param array<string, mixed>|null $counters
     * @param array<string, mixed>|null $gauges
     * @param list<string> $streams обязательные серии лага и pending
     */
    public static function fromCache(?array $counters, ?array $gauges, array $streams): self
    {
        $counterDocument = $counters ?? [];
        $gaugeDocument = $gauges ?? [];

        return new self(
            counters: [
                self::SENT => self::intMap($counterDocument[self::SENT] ?? null),
                self::FAILED => self::intMap($counterDocument[self::FAILED] ?? null),
                self::RETRIED => self::intMap($counterDocument[self::RETRIED] ?? null),
            ],
            durations: self::durationMap($counterDocument['durations'] ?? null),
            dlqDepth: self::dlqRows($gaugeDocument['dlq_depth'] ?? null),
            consumers: self::consumerRows($gaugeDocument['consumer'] ?? null, $streams),
            outboxOldestUnpublishedSeconds: max(0, (int) ($gaugeDocument['outbox_oldest_unpublished_seconds'] ?? 0)),
            degraded: self::strings([
                ...self::strings($counters === null ? ['cache'] : []),
                ...self::strings($gaugeDocument['degraded'] ?? null),
                ...self::strings($gauges === null ? ['cache'] : []),
            ]),
            generatedAt: self::moment(
                $gaugeDocument['generated_at'] ?? $counterDocument['generated_at'] ?? null,
            ),
        );
    }

    /**
     * Состояние снимка: `ok` или `degraded`.
     */
    public function status(): string
    {
        return $this->degraded === [] ? self::STATUS_OK : self::STATUS_DEGRADED;
    }

    /**
     * Снимок в JSON-виде команды и вкладки «Метрики» (FR-OPS-511).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $counters = [];

        foreach ($this->counters as $metric => $values) {
            foreach ($values as $channel => $value) {
                $counters[] = ['channel' => (string) $channel, 'metric' => $metric, 'value' => $value];
            }
        }

        $durations = [];

        foreach ($this->durations as $channel => $entry) {
            $durations[] = [
                'channel' => (string) $channel,
                'count' => $entry['count'],
                'sum' => $entry['sum'],
                'buckets' => $entry['buckets'],
            ];
        }

        return [
            'generated_at' => $this->generatedAt->toIso8601String(),
            'status' => $this->status(),
            'counters' => $counters,
            'durations' => $durations,
            'dlq_depth' => $this->dlqDepth,
            'consumer' => $this->consumers,
            'outbox_oldest_unpublished_seconds' => $this->outboxOldestUnpublishedSeconds,
            'degraded' => $this->degraded,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function intMap(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $channel => $count) {
            if (is_string($channel) && $channel !== '' && is_numeric($count)) {
                $result[$channel] = (int) $count;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<string, array{buckets: array<int|string, int>, sum: float, count: int}>
     */
    private static function durationMap(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $channel => $entry) {
            if (! is_string($channel) || $channel === '' || ! is_array($entry)) {
                continue;
            }

            $buckets = [];

            foreach (is_array($entry['buckets'] ?? null) ? $entry['buckets'] : [] as $le => $count) {
                // Числовые ключи PHP приводит к int: граница `1` приходит из
                // кэша как int, поэтому ключ всегда приводится к строке.
                if (is_numeric($count)) {
                    $buckets[(string) $le] = (int) $count;
                }
            }

            $result[$channel] = [
                'buckets' => $buckets,
                'sum' => is_numeric($entry['sum'] ?? null) ? (float) $entry['sum'] : 0.0,
                'count' => is_numeric($entry['count'] ?? null) ? (int) $entry['count'] : 0,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Глубина DLQ: канал, состояние разбора и значение (FR-OPS-504).
     *
     * @return list<array{channel: string, state: string, value: int}>
     */
    private static function dlqRows(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'channel' => (string) ($row['channel'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'value' => max(0, (int) ($row['value'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * Серии лага и pending: по каждому потоку из конфигурации (FR-OPS-505).
     *
     * @param list<string> $streams
     * @return list<array{stream: string, lag_seconds: int, pending: int}>
     */
    private static function consumerRows(mixed $value, array $streams): array
    {
        $known = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row) || ! is_string($row['stream'] ?? null) || $row['stream'] === '') {
                continue;
            }

            $known[$row['stream']] = [
                'stream' => $row['stream'],
                'lag_seconds' => max(0, (int) ($row['lag_seconds'] ?? 0)),
                'pending' => max(0, (int) ($row['pending'] ?? 0)),
            ];
        }

        $result = [];

        foreach ($streams as $stream) {
            $result[$stream] = $known[$stream] ?? ['stream' => $stream, 'lag_seconds' => 0, 'pending' => 0];
        }

        foreach ($known as $stream => $row) {
            $result[$stream] ??= $row;
        }

        return array_values($result);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    private static function moment(mixed $value): Carbon
    {
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->utc();
            } catch (Throwable) {
                return Carbon::now()->utc();
            }
        }

        return Carbon::now()->utc();
    }
}
