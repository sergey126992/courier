<?php

declare(strict_types=1);

namespace App\Metrics;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Лаг и число ожидающих сообщений consumer group потока (FR-OPS-505).
 *
 * Источник значений — данные consumer group Redis Streams, а не разбор
 * текста логов: число `pending` из `XINFO GROUPS`, возраст самой старой
 * ожидающей записи из `XPENDING` с `IDLE`, возраст первой непрочитанной
 * записи из `XRANGE`. Те же правила использует проверка `/health`;
 * здесь запрос повторяется по каждому потоку, а не только по заказам.
 *
 * Redis недоступен — метод отвечает `null`: снимок помечается `degraded`,
 * но задача продолжает работу (FR-OPS-512).
 */
final class StreamLagProbe
{
    public function __construct(
        private readonly string $consumerGroup,
        private readonly int $batchSize,
    ) {}

    /**
     * @return array{lag_seconds: int, pending: int}|null `null` — Redis недоступен
     */
    public function sample(string $stream): ?array
    {
        try {
            $connection = Redis::connection();
            $connection->command('ping');

            try {
                /** @var mixed $rawGroups */
                $rawGroups = $connection->command('xinfo', ['GROUPS', $stream]);
            } catch (Throwable $exception) {
                // Потока ещё нет: Redis жив, группа не создана.
                return $this->isMissingStream($exception) ? ['lag_seconds' => 0, 'pending' => 0] : null;
            }

            if (! is_array($rawGroups)) {
                return null;
            }

            $group = $this->groupEntry($rawGroups);

            if ($group === null) {
                return ['lag_seconds' => 0, 'pending' => 0];
            }

            $pending = max(0, (int) ($group['pending'] ?? 0));

            return [
                'lag_seconds' => $this->lagSeconds($connection, $stream, $group, $pending),
                'pending' => $pending,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Описание consumer group сервиса из ответа `XINFO GROUPS`.
     *
     * @param array<mixed> $groups
     * @return array<string, mixed>|null
     */
    private function groupEntry(array $groups): ?array
    {
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            if (($group['name'] ?? null) === $this->consumerGroup) {
                /** @var array<string, mixed> $group */
                return $group;
            }
        }

        return null;
    }

    /**
     * Возраст самой старой необработанной записи группы в секундах.
     *
     * @param array<string, mixed> $group
     */
    private function lagSeconds(Connection $connection, string $stream, array $group, int $pending): int
    {
        if ($pending > 0) {
            $idle = $this->pendingIdleMilliseconds($connection, $stream);

            return $idle === null ? 0 : intdiv($idle, 1000);
        }

        $unread = $group['lag'] ?? null;

        if (! is_numeric($unread) || (int) $unread < 1) {
            return 0;
        }

        return $this->unreadAgeSeconds($connection, $stream, $group);
    }

    /**
     * Простой самой старой ожидающей записи в миллисекундах.
     */
    private function pendingIdleMilliseconds(Connection $connection, string $stream): ?int
    {
        /** @var mixed $entries */
        $entries = $connection->command('xpending', [
            $stream,
            $this->consumerGroup,
            '-',
            '+',
            max(1, $this->batchSize),
        ]);

        if (! is_array($entries)) {
            return null;
        }

        $idle = null;

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry[2]) || ! is_numeric($entry[2])) {
                continue;
            }

            $value = (int) $entry[2];
            $idle = $idle === null ? $value : max($idle, $value);
        }

        return $idle;
    }

    /**
     * Возраст первой непрочитанной записи потока по её `occurred_at`.
     *
     * @param array<string, mixed> $group
     */
    private function unreadAgeSeconds(Connection $connection, string $stream, array $group): int
    {
        $lastDelivered = (string) ($group['last-delivered-id'] ?? '0-0');

        /** @var mixed $entries */
        $entries = $connection->command('xrange', [$stream, '('.($lastDelivered), '+', 'COUNT', 1]);

        if (! is_array($entries) || $entries === []) {
            return 0;
        }

        $entry = reset($entries);

        if (! is_array($entry) || ! isset($entry[1]) || ! is_array($entry[1])) {
            return 0;
        }

        $occurredAt = $entry[1]['occurred_at'] ?? null;

        if (! is_string($occurredAt) || $occurredAt === '') {
            return 0;
        }

        try {
            $age = Carbon::parse($occurredAt)->diffInSeconds(Carbon::now(), true);
        } catch (Throwable) {
            return 0;
        }

        return (int) $age;
    }

    private function isMissingStream(Throwable $exception): bool
    {
        $message = strtoupper($exception->getMessage());

        return str_contains($message, 'NO SUCH KEY') || str_contains($message, 'NOGROUP');
    }
}
