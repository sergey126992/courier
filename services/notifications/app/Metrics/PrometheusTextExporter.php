<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Экспорт снимка метрик в текстовом формате Prometheus 0.0.4 (FR-OPS-501).
 *
 * Собственный экспортёр: набор метрик стабилен и мал, а формат проверяется
 * unit-тестом строк (ADR-0018). Сборщик читает ответ без доработок:
 * `# HELP`/`# TYPE` у каждой метрики, гистограмма с `_bucket`/`_sum`/`_count`
 * и границей `+Inf`, значения без экспоненты.
 *
 * Метки закрыты белым списком (FR-OPS-507): имя вне списка отбрасывается,
 * поэтому идентификаторы получателя, заказа и уведомления в экспорт
 * попасть не могут даже при ошибке в источнике. Исключение одно —
 * `le` гистограммы: это служебная метка формата, а не метка домена.
 */
final class PrometheusTextExporter
{
    /**
     * Content-Type ответа `/metrics` (FR-OPS-501).
     */
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * Счётчик отправленных уведомлений по каналам (FR-OPS-502).
     */
    public const SENT_TOTAL = 'notifications_sent_total';

    /**
     * Счётчик окончательных отказов по каналам (FR-OPS-502).
     */
    public const FAILED_TOTAL = 'notifications_failed_total';

    /**
     * Счётчик повторённых уведомлений по каналам (FR-OPS-502).
     */
    public const RETRIED_TOTAL = 'notifications_retried_total';

    /**
     * Гистограмма длительности отправки по каналам (FR-OPS-503).
     */
    public const SEND_DURATION_SECONDS = 'notifications_send_duration_seconds';

    /**
     * Глубина очереди необработанных по каналам и состояниям (FR-OPS-504).
     */
    public const DLQ_DEPTH = 'notifications_dlq_depth';

    /**
     * Лаг потребления по потокам (FR-OPS-505).
     */
    public const CONSUMER_LAG_SECONDS = 'notifications_consumer_lag_seconds';

    /**
     * Число ожидающих подтверждения сообщений по потокам (FR-OPS-505).
     */
    public const CONSUMER_PENDING = 'notifications_consumer_pending';

    /**
     * Возраст старейшего неопубликованного события Outbox (FR-OPS-506).
     */
    public const OUTBOX_OLDEST_UNPUBLISHED_SECONDS = 'notifications_outbox_oldest_unpublished_seconds';

    /**
     * Тип счётчика.
     */
    private const TYPE_COUNTER = 'counter';

    /**
     * Тип gauge.
     */
    private const TYPE_GAUGE = 'gauge';

    /**
     * Тип гистограммы.
     */
    private const TYPE_HISTOGRAM = 'histogram';

    /**
     * Служебная метка границы гистограммы.
     */
    private const BUCKET_LABEL = 'le';

    /**
     * Верхняя граница гистограммы «бесконечность».
     */
    private const INFINITE_BUCKET = '+Inf';

    /**
     * @param list<string> $allowedLabels белый список меток (FR-OPS-507)
     */
    public function __construct(private readonly array $allowedLabels) {}

    /**
     * Полный текст ответа `/metrics`.
     */
    public function export(MetricsSnapshot $snapshot): string
    {
        return $this->render(self::SENT_TOTAL, 'Отправленные уведомления по каналам', self::TYPE_COUNTER, $this->channelSamples(
            self::SENT_TOTAL,
            $snapshot->counters[MetricsSnapshot::SENT] ?? [],
        ))
            .$this->render(self::FAILED_TOTAL, 'Окончательно неотправленные уведомления по каналам', self::TYPE_COUNTER, $this->channelSamples(
                self::FAILED_TOTAL,
                $snapshot->counters[MetricsSnapshot::FAILED] ?? [],
            ))
            .$this->render(self::RETRIED_TOTAL, 'Повторённые уведомления по каналам', self::TYPE_COUNTER, $this->channelSamples(
                self::RETRIED_TOTAL,
                $snapshot->counters[MetricsSnapshot::RETRIED] ?? [],
            ))
            .$this->renderHistogram($snapshot->durations)
            .$this->render(self::DLQ_DEPTH, 'Глубина очереди необработанных по каналам и состояниям', self::TYPE_GAUGE, $this->dlqSamples($snapshot->dlqDepth))
            .$this->render(self::CONSUMER_LAG_SECONDS, 'Возраст самого старого необработанного сообщения потока', self::TYPE_GAUGE, $this->consumerSamples($snapshot->consumers, 'lag_seconds'))
            .$this->render(self::CONSUMER_PENDING, 'Сообщения потока, ожидающие подтверждения', self::TYPE_GAUGE, $this->consumerSamples($snapshot->consumers, 'pending'))
            .$this->render(self::OUTBOX_OLDEST_UNPUBLISHED_SECONDS, 'Возраст самого старого неопубликованного события Outbox', self::TYPE_GAUGE, [
                new PrometheusSample(self::OUTBOX_OLDEST_UNPUBLISHED_SECONDS, [], (float) $snapshot->outboxOldestUnpublishedSeconds),
            ]);
    }

    /**
     * Блок `# HELP`/`# TYPE` и строки серий одной метрики.
     *
     * @param list<PrometheusSample> $samples
     */
    public function render(string $name, string $help, string $type, array $samples): string
    {
        $text = '# HELP '.$name.' '.self::escapeHelp($help)."\n";
        $text .= '# TYPE '.$name.' '.$type."\n";

        foreach ($samples as $sample) {
            $text .= $sample->name.$this->labels($sample->labels).' '.MetricsFormat::float($sample->value)."\n";
        }

        return $text;
    }

    /**
     * Гистограмма длительности: накопительные границы и `+Inf`.
     *
     * @param array<string, array{buckets: array<int|string, int>, sum: float, count: int}> $durations
     */
    private function renderHistogram(array $durations): string
    {
        $samples = [];

        foreach ($durations as $channel => $entry) {
            $labels = ['channel' => (string) $channel];
            $buckets = $entry['buckets'];
            uksort($buckets, static fn (int|string $left, int|string $right): int => (float) $left <=> (float) $right);

            foreach ($buckets as $le => $count) {
                $samples[] = new PrometheusSample(
                    self::SEND_DURATION_SECONDS.'_bucket',
                    $labels + [self::BUCKET_LABEL => (string) $le],
                    (float) $count,
                );
            }

            $samples[] = new PrometheusSample(
                self::SEND_DURATION_SECONDS.'_bucket',
                $labels + [self::BUCKET_LABEL => self::INFINITE_BUCKET],
                (float) $entry['count'],
            );
            $samples[] = new PrometheusSample(self::SEND_DURATION_SECONDS.'_sum', $labels, $entry['sum']);
            $samples[] = new PrometheusSample(self::SEND_DURATION_SECONDS.'_count', $labels, (float) $entry['count']);
        }

        return $this->render(self::SEND_DURATION_SECONDS, 'Длительность отправки по каналам', self::TYPE_HISTOGRAM, $samples);
    }

    /**
     * @param array<string, int> $values значения по каналам
     * @return list<PrometheusSample>
     */
    private function channelSamples(string $name, array $values): array
    {
        $samples = [];

        foreach ($values as $channel => $value) {
            $samples[] = new PrometheusSample($name, ['channel' => (string) $channel], (float) $value);
        }

        return $samples;
    }

    /**
     * @param list<array{channel: string, state: string, value: int}> $rows
     * @return list<PrometheusSample>
     */
    private function dlqSamples(array $rows): array
    {
        $samples = [];

        foreach ($rows as $row) {
            $samples[] = new PrometheusSample(self::DLQ_DEPTH, [
                'channel' => $row['channel'],
                'state' => $row['state'],
            ], (float) $row['value']);
        }

        return $samples;
    }

    /**
     * @param list<array{stream: string, lag_seconds: int, pending: int}> $rows
     * @return list<PrometheusSample>
     */
    private function consumerSamples(array $rows, string $field): array
    {
        $samples = [];

        foreach ($rows as $row) {
            $samples[] = new PrometheusSample(
                $field === 'pending' ? self::CONSUMER_PENDING : self::CONSUMER_LAG_SECONDS,
                ['stream' => $row['stream']],
                (float) $row[$field],
            );
        }

        return $samples;
    }

    /**
     * Метки серии: белый список плюс служебная метка гистограммы.
     *
     * @param array<string, string> $labels
     */
    private function labels(array $labels): string
    {
        $allowed = [];

        foreach ($labels as $name => $value) {
            if ($name === self::BUCKET_LABEL || in_array($name, $this->allowedLabels, true)) {
                $allowed[$name] = $value;
            }
        }

        if ($allowed === []) {
            return '';
        }

        ksort($allowed);

        $parts = [];

        foreach ($allowed as $name => $value) {
            $parts[] = $name.'="'.self::escapeLabelValue((string) $value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function escapeLabelValue(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value,
        );
    }

    private static function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }
}
