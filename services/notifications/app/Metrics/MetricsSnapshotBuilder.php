<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Domain\Notifications\DlqState;
use App\Logging\TechnicalLogger;
use App\Models\OutboxMessage;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Снимок «дорогих» gauge задачи расписания (FR-OPS-504…508).
 *
 * `/metrics` не пересчитывает базу: глубину DLQ, лаг и pending, возраст
 * старейшего неопубликованного события Outbox считает эта задача и кладёт
 * в общий кэш. HTTP-процесс только читает готовый документ, поэтому цена
 * запроса `/metrics` не зависит от объёма журнала.
 *
 * Недоступность источника не роняет задачу: соответствующие значения
 * остаются нулевыми, а снимок получает код `degraded` (FR-OPS-512).
 */
final class MetricsSnapshotBuilder
{
    /**
     * @param list<string> $streams потоки конфигурации без префикса окружения
     */
    public function __construct(
        private readonly MetricsStore $store,
        private readonly StreamLagProbe $probe,
        private readonly TechnicalLogger $logger,
        private readonly array $streams,
        private readonly string $streamPrefix,
    ) {}

    /**
     * Снимок из кэша; при отсутствии документа или `--refresh` — пересчёт.
     */
    public function current(bool $refresh = false): MetricsSnapshot
    {
        $gauges = $refresh ? [] : ($this->store->readGauges() ?? []);

        if ($gauges === []) {
            $gauges = $this->collectAndStore();
        }

        return MetricsSnapshot::fromCache($this->store->readCounters(), $gauges, $this->streamNames());
    }

    /**
     * Пересчёт gauge и запись документа в кэш.
     *
     * @return array<string, mixed>
     */
    public function collectAndStore(): array
    {
        $degraded = [];

        $document = [
            'dlq_depth' => $this->dlqDepth($degraded),
            'consumer' => $this->consumer($degraded),
            'outbox_oldest_unpublished_seconds' => $this->outboxOldestUnpublishedSeconds($degraded),
            'degraded' => $degraded,
            'generated_at' => Carbon::now()->utc()->toIso8601String(),
        ];

        if ($degraded !== []) {
            $this->logger->warning('Снимок метрик построен частично.', [
                'status' => 'degraded',
                'error_code' => 'METRICS_UNAVAILABLE',
            ]);
        }

        if (! $this->store->writeGauges($document)) {
            $this->logger->warning('Снимок метрик не записан в кэш.', [
                'status' => 'degraded',
                'error_code' => 'METRICS_UNAVAILABLE',
            ]);
        }

        return $document;
    }

    /**
     * Глубина DLQ по каналам и состояниям разбора (FR-OPS-504).
     *
     * Учитываются только неразобранные записи (`open`, `replayed`):
     * значение соответствует числу ожидающих записей, а не всей таблице.
     *
     * @param list<string> $degraded
     * @return list<array{channel: string, state: string, value: int}>
     */
    private function dlqDepth(array &$degraded): array
    {
        try {
            $rows = DB::table('dlq_entries')
                ->select('channel', 'state', DB::raw('count(*) as aggregate'))
                ->whereIn('state', $this->unresolvedStates())
                ->groupBy('channel', 'state')
                ->orderBy('channel')
                ->orderBy('state')
                ->get();
        } catch (Throwable) {
            $degraded[] = 'dlq';

            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'channel' => (string) $row->channel,
                'state' => (string) $row->state,
                'value' => max(0, (int) $row->aggregate),
            ];
        }

        return $result;
    }

    /**
     * Лаг и pending по каждому потоку (FR-OPS-505).
     *
     * @param list<string> $degraded
     * @return list<array{stream: string, lag_seconds: int, pending: int}>
     */
    private function consumer(array &$degraded): array
    {
        $rows = [];

        foreach ($this->streamNames() as $stream) {
            $sample = $this->probe->sample($stream);

            if ($sample === null) {
                $degraded[] = 'consumer';
                $rows[] = ['stream' => $stream, 'lag_seconds' => 0, 'pending' => 0];

                continue;
            }

            $rows[] = ['stream' => $stream, 'lag_seconds' => $sample['lag_seconds'], 'pending' => $sample['pending']];
        }

        return $rows;
    }

    /**
     * Возраст старейшего неопубликованного события Outbox (FR-OPS-506).
     *
     * @param list<string> $degraded
     */
    private function outboxOldestUnpublishedSeconds(array &$degraded): int
    {
        try {
            $oldest = OutboxMessage::query()->whereNull('published_at')->min('created_at');
        } catch (Throwable) {
            $degraded[] = 'outbox';

            return 0;
        }

        // `min()` возвращает значение колонки как есть: строку или объект даты
        // в зависимости от драйвера, поэтому поддержаны оба варианта.
        if ($oldest instanceof DateTimeInterface) {
            $moment = Carbon::instance($oldest)->utc();
        } elseif (is_string($oldest) && $oldest !== '') {
            try {
                $moment = Carbon::parse($oldest)->utc();
            } catch (Throwable) {
                $degraded[] = 'outbox';

                return 0;
            }
        } else {
            return 0;
        }

        return max(0, Carbon::now()->getTimestamp() - $moment->getTimestamp());
    }

    /**
     * Состояния DLQ, которые участвуют в глубине очереди.
     *
     * @return list<string>
     */
    private function unresolvedStates(): array
    {
        $states = [];

        foreach (DlqState::cases() as $state) {
            if ($state->isUnresolved()) {
                $states[] = $state->value;
            }
        }

        return $states;
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
