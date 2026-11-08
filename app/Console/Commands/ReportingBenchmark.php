<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reporting\Benchmark\BenchmarkDataset;
use App\Services\Reporting\Benchmark\BenchmarkOptions;
use App\Services\Reporting\Benchmark\BenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Воспроизводимый замер отчёта по заказам (FR-RPT-163…168).
 *
 * Команда проверяет окружение и параметры, наполняет детерминированный набор
 * через BenchmarkDataset, замеряет отчёт через BenchmarkRunner и печатает
 * результат. По умолчанию набор удаляется в `finally`, поэтому прогон не
 * оставляет данных; флаг --keep оставляет набор для ручных экспериментов,
 * и следующий прогон сначала вычищает остатки по маркеру `BENCH-`.
 *
 * Наполнение допускается только в окружениях из config('reporting.benchmark.environments')
 * (FR-RPT-168): в остальных команда отказывает до любых изменений в базе.
 * Кандидаты индексов создаются только по флагу --with-indexes и удаляются
 * в `finally`, поэтому «до» и «после» снимаются на одном наборе.
 */
final class ReportingBenchmark extends Command
{
    /**
     * Замер выполнен.
     */
    private const EXIT_OK = 0;

    /**
     * Окружение не разрешает наполнение.
     */
    private const EXIT_REFUSED = 1;

    /**
     * Ошибка выполнения или остались данные бенчмарка.
     */
    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'reporting:benchmark
        {--orders= : Размер набора заказов}
        {--runs= : Число замеров отчётного сервиса}
        {--seed= : Зерно детерминированного генератора}
        {--explain : Снимает EXPLAIN (ANALYZE, BUFFERS) для трёх запросов}
        {--with-indexes : Создаёт кандидаты индексов на время замера}
        {--json : Вывод замера в JSON}
        {--keep : Не удалять набор после прогона}';

    /**
     * @var string
     */
    protected $description = 'Замеряет отчёт по заказам на детерминированном наборе и снимает планы выполнения';

    public function handle(): int
    {
        $environment = (string) app()->environment();

        // Отказ до любых изменений в базе (FR-RPT-168).
        if (! in_array($environment, $this->environments(), true)) {
            return $this->reportError(sprintf(
                'Бенчмарк отчёта запускается только в окружениях %s; текущее окружение — %s.',
                implode(', ', $this->environments()),
                $environment,
            ), self::EXIT_REFUSED);
        }

        try {
            $options = $this->parseOptions();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage(), self::EXIT_ERROR);
        }

        $dataset = app(BenchmarkDataset::class);
        $runner = app(BenchmarkRunner::class);

        $payload = null;
        $leftovers = [];
        $error = null;

        try {
            // Остатки прошлого --keep не мешают повторному прогону.
            $dataset->cleanup();
            $dataset->seed($options);

            $indexes = $this->option('with-indexes') === true ? $runner->createIndexes() : [];

            try {
                $payload = $runner->run($options, $indexes, $this->option('explain') === true);
            } finally {
                $runner->dropIndexes($indexes);
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        } finally {
            if (! $this->option('keep')) {
                try {
                    $leftovers = $dataset->cleanup();
                } catch (Throwable $exception) {
                    $error ??= $exception->getMessage();
                    $leftovers = ['cleanup' => 1];
                }
            }
        }

        if ($error !== null) {
            return $this->reportError($error, self::EXIT_ERROR);
        }

        if ($leftovers !== []) {
            return $this->reportError(sprintf(
                'После прогона остались строки бенчмарка: %s.',
                (string) json_encode($leftovers, JSON_UNESCAPED_UNICODE),
            ), self::EXIT_ERROR);
        }

        if ($payload === null) {
            return $this->reportError('Замер не выполнен.', self::EXIT_ERROR);
        }

        if ($this->option('keep')) {
            $payload['kept'] = true;
        }

        $this->render($payload);

        return self::EXIT_OK;
    }

    /**
     * Окружения, где допустимо наполнение набора (FR-RPT-168).
     *
     * @return list<string>
     */
    private function environments(): array
    {
        $configured = config('reporting.benchmark.environments');

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $configured),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Параметры запуска с проверкой значений.
     *
     * @throws InvalidArgumentException значение флага не положительное целое
     */
    private function parseOptions(): BenchmarkOptions
    {
        $to = Carbon::now()->startOfSecond();

        return new BenchmarkOptions(
            orders: $this->intOption('orders', (int) config('reporting.benchmark.orders'), 1),
            runs: $this->intOption('runs', (int) config('reporting.benchmark.runs'), 1),
            seed: $this->intOption('seed', 42, 0),
            from: $to->copy()->subDays(BenchmarkOptions::PERIOD_DAYS),
            to: $to,
        );
    }

    /**
     * Целое значение флага или значение по умолчанию.
     *
     * @throws InvalidArgumentException значение меньше допустимого или не число
     */
    private function intOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value) || (int) $value < $minimum) {
            throw new InvalidArgumentException(sprintf(
                'Флаг --%s должен быть целым числом не меньше %d.',
                $name,
                $minimum,
            ));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return;
        }

        /** @var array<string, int> $dataset */
        $dataset = is_array($payload['dataset'] ?? null) ? $payload['dataset'] : [];
        /** @var array<string, float|int> $metrics */
        $metrics = is_array($payload['metrics_ms'] ?? null) ? $payload['metrics_ms'] : [];

        $this->info('Замер отчёта по заказам завершён.');
        $this->line(sprintf('Окружение: %s, PostgreSQL %s.', (string) $payload['environment'], (string) $payload['postgres_version']));
        $this->line(sprintf(
            'Набор: %d заказов, %d курьеров, %d вручений, %d смен; seed %d.',
            (int) ($dataset['orders'] ?? 0),
            (int) ($dataset['couriers'] ?? 0),
            (int) ($dataset['assignments'] ?? 0),
            (int) ($dataset['shifts'] ?? 0),
            (int) $payload['seed'],
        ));
        $this->line(sprintf('Замеров: %d, SQL на отчёт: %d.', (int) $payload['runs'], (int) $payload['sql_queries']));
        $this->line(sprintf(
            'Время отчёта: p50 %.3f мс, p95 %.3f мс, min %.3f мс, max %.3f мс.',
            (float) ($metrics['p50'] ?? 0.0),
            (float) ($metrics['p95'] ?? 0.0),
            (float) ($metrics['min'] ?? 0.0),
            (float) ($metrics['max'] ?? 0.0),
        ));
        $this->line(sprintf(
            'Кандидаты индексов: %s.',
            $payload['with_indexes'] === true ? implode(', ', (array) $payload['indexes']) : 'нет',
        ));
    }

    /**
     * Отказ в едином формате: JSON при --json, иначе текст ошибки.
     */
    private function reportError(string $message, int $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => 'error',
                'environment' => (string) app()->environment(),
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return $code;
    }
}
