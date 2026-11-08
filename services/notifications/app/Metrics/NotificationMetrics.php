<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Logging\TechnicalLogger;

/**
 * Снимок метрик для HTTP `/metrics` (FR-OPS-501, FR-OPS-508).
 *
 * Читается только кэш: база на запрос не агрегируется, поэтому ответ
 * дешёвый и не зависит от объёма журнала. Счётчики и гистограмму туда
 * кладёт процесс отправки, gauge — задача расписания.
 *
 * Недоступный кэш не роняет сервис: часть значений остаётся нулевой,
 * а в журнал пишется код `degraded` (FR-OPS-512).
 */
final class NotificationMetrics
{
    /**
     * @param list<string> $streams потоки конфигурации без префикса окружения
     */
    public function __construct(
        private readonly MetricsStore $store,
        private readonly TechnicalLogger $logger,
        private readonly array $streams,
        private readonly string $streamPrefix,
    ) {}

    public function snapshot(): MetricsSnapshot
    {
        $counters = $this->store->readCounters();
        $gauges = $this->store->readGauges();

        if ($counters === null || $gauges === null) {
            $this->logger->warning('Источник метрик недоступен, ответ частичный.', [
                'status' => 'degraded',
                'error_code' => 'METRICS_UNAVAILABLE',
            ]);
        }

        return MetricsSnapshot::fromCache($counters, $gauges, $this->streamNames());
    }

    /**
     * Полные имена потоков с префиксом окружения (FR-OPS-211).
     *
     * @return list<string>
     */
    private function streamNames(): array
    {
        $names = [];

        foreach ($this->streams as $stream) {
            if (is_string($stream) && $stream !== '') {
                $names[] = $this->streamPrefix.$stream;
            }
        }

        return $names;
    }
}
