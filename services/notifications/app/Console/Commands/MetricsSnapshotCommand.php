<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Metrics\MetricsSnapshot;
use App\Metrics\MetricsSnapshotBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Снимок метрик сервиса для демонстрации и вкладки «Метрики» (FR-OPS-511).
 *
 * Задача пересчитывает «дорогие» gauge (глубина DLQ, лаг и pending потоков,
 * возраст старейшего неопубликованного события Outbox) и кладёт документ
 * в общий кэш с TTL `metrics.snapshot_ttl_seconds`. `/metrics` после этого
 * только читает кэш (FR-OPS-508).
 *
 * `--refresh` пересчитывает значения, не дожидаясь истечения TTL; `--json`
 * выводит снимок в машиночитаемом виде. Код возврата: 0 — снимок построен,
 * 2 — сбор завершился ошибкой.
 */
final class MetricsSnapshotCommand extends Command
{
    /**
     * Снимок построен (в том числе частично).
     */
    private const EXIT_OK = 0;

    /**
     * Сбор снимка завершился ошибкой.
     */
    private const EXIT_FAILED = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:metrics-snapshot
        {--json : Вывод снимка в JSON}
        {--refresh : Пересчитать gauge и обновить кэш}';

    /**
     * @var string
     */
    protected $description = 'Строит снимок метрик сервиса и заполняет кэш gauge-значений';

    public function handle(MetricsSnapshotBuilder $builder): int
    {
        try {
            $snapshot = $builder->current((bool) $this->option('refresh'));
        } catch (Throwable) {
            $this->error('Сбор метрик завершился ошибкой.');

            return self::EXIT_FAILED;
        }

        $this->render($snapshot);

        return self::EXIT_OK;
    }

    private function render(MetricsSnapshot $snapshot): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $snapshot->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        $this->line('Снимок метрик сервиса, состояние: '.$snapshot->status().'.');

        foreach ($snapshot->counters as $metric => $values) {
            foreach ($values as $channel => $value) {
                $this->line(sprintf('  %s{channel="%s"} %d', $metric, $channel, $value));
            }
        }

        foreach ($snapshot->dlqDepth as $row) {
            $this->line(sprintf('  dlq_depth{channel="%s",state="%s"} %d', $row['channel'], $row['state'], $row['value']));
        }

        foreach ($snapshot->consumers as $row) {
            $this->line(sprintf(
                '  consumer{stream="%s"} lag=%d pending=%d',
                $row['stream'],
                $row['lag_seconds'],
                $row['pending'],
            ));
        }

        $this->line(sprintf('  outbox_oldest_unpublished_seconds %d', $snapshot->outboxOldestUnpublishedSeconds));
    }
}
