<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Events\BrokerHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Состояние брокера RabbitMQ (FR-NTF-552).
 *
 * Показывает соединение, глубину очередей потребителей и DLQ. При транспорте
 * `redis` команда отвечает кодом 0 и `enabled: false`: профиль `broker`
 * не обязателен для основного запуска (FR-NTF-546). Вывод не содержит
 * адресов, секретов и персональных данных (FR-NTF-549).
 *
 * Коды возврата: 0 — брокер в норме или выключен; 1 — брокер недоступен.
 */
final class BrokerHealthCommand extends Command
{
    /**
     * Брокер в норме или проверка не применима.
     */
    public const EXIT_OK = 0;

    /**
     * Брокер недоступен или очередь не читается.
     */
    public const EXIT_ERROR = 1;

    /**
     * @var string
     */
    protected $signature = 'notifications:broker-health {--json : Вывести снимок в JSON}';

    /**
     * @var string
     */
    protected $description = 'Состояние соединения брокера, очередей и DLQ';

    public function handle(BrokerHealth $health): int
    {
        $snapshot = $health->snapshot();
        $status = $health->status();
        $exit = $status === BrokerHealth::STATUS_ERROR ? self::EXIT_ERROR : self::EXIT_OK;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'command' => $this->getName(),
                'status' => $status,
                ...$snapshot,
                'generated_at' => Carbon::now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->info(sprintf(
            '%s: транспорт %s, брокер %s, соединение %s, очередей %d, DLQ %s',
            $this->getName(),
            $snapshot['driver'],
            $snapshot['enabled'] ? 'включён' : 'выключен',
            $snapshot['connected'] ? 'есть' : 'нет',
            count($snapshot['queues']),
            $snapshot['dlq']['depth'] === null ? 'n/a' : (string) $snapshot['dlq']['depth'],
        ));

        foreach ($snapshot['queues'] as $queue => $depth) {
            $this->line(sprintf('  %s: %s', $queue, $depth === null ? 'n/a' : (string) $depth));
        }

        return $exit;
    }
}
