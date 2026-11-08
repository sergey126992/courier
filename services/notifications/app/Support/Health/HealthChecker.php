<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Services\Events\BrokerHealth;
use App\Support\Contracts\SchemaDirectory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Проверка состояния сервиса для внешнего мониторинга (FR-NTF-214,
 * FR-OPS-203, FR-OPS-204, FR-OPS-210).
 *
 * Проверяются база, Redis, читаемость общих JSON Schema и лаг потребления
 * потока заказов. Пороги лага и числа необработанных берутся из конфигурации:
 * превышение даёт degraded, но процесс продолжает работу. Сбой зависимости —
 * тоже деградация, а не исключение: сервис не падает из-за недоступного Redis.
 *
 * Ответ содержит только состояния и счётчики: персональных данных, токенов,
 * адресов, числа контактов и идентификаторов пользователей нет.
 */
final class HealthChecker
{
    /**
     * Значение проверки в норме.
     */
    public const CHECK_OK = 'ok';

    /**
     * Значение проверки при сбое.
     */
    public const CHECK_ERROR = 'error';

    public function __construct(
        private readonly SchemaDirectory $schemas,
        private readonly BrokerHealth $broker,
    ) {}

    public function check(): HealthReport
    {
        $database = $this->databaseStatus();
        $contracts = $this->contractsStatus();
        $redis = $this->redisMetrics();
        // Состояние брокера проверяется только при транспорте rabbitmq:
        // при redis прежние поля и их порядок не меняются (FR-NTF-548).
        $broker = $this->broker->enabled() ? $this->broker->status() : null;

        $degraded = $database !== self::CHECK_OK
            || $contracts !== self::CHECK_OK
            || $redis['status'] !== self::CHECK_OK
            || $this->lagExceeded($redis['lag_seconds'])
            || $this->pendingExceeded($redis['pending'])
            || $broker === BrokerHealth::STATUS_ERROR;

        return new HealthReport(
            status: $degraded ? HealthReport::STATUS_DEGRADED : HealthReport::STATUS_OK,
            checks: [
                'database' => $database,
                'redis' => $redis['status'],
                'contracts' => $contracts,
            ],
            lagSeconds: $redis['lag_seconds'],
            pending: $redis['pending'],
            generatedAt: Carbon::now(),
            broker: $broker,
        );
    }

    /**
     * Доступность базы сервиса.
     */
    private function databaseStatus(): string
    {
        try {
            DB::connection()->select('select 1');

            return self::CHECK_OK;
        } catch (Throwable) {
            return self::CHECK_ERROR;
        }
    }

    /**
     * Читаемость каталога общих JSON Schema (FR-OPS-209).
     */
    private function contractsStatus(): string
    {
        return $this->schemas->isReadable() ? self::CHECK_OK : self::CHECK_ERROR;
    }

    /**
     * Лаг и число необработанных по consumer group потока заказов (FR-OPS-203).
     *
     * Лаг — возраст самой старой необработанной записи группы: сообщения,
     * ожидающие подтверждения (pending), и ещё не прочитанные записи. Значения
     * null означают, что сведения получить не удалось: Redis недоступен.
     *
     * @return array{status: string, lag_seconds: int|null, pending: int|null}
     */
    private function redisMetrics(): array
    {
        try {
            $connection = Redis::connection();

            // Доступность Redis подтверждается отдельной командой: XINFO на
            // отсутствующем потоке отвечает ошибкой «no such key».
            $connection->command('ping');

            $stream = $this->ordersStream();

            try {
                /** @var mixed $rawGroups */
                $rawGroups = $connection->command('xinfo', ['GROUPS', $stream]);
            } catch (Throwable $exception) {
                // Потока ещё нет: Redis жив, группа не создана (ЧТЗ 5.2).
                if ($this->isMissingStream($exception)) {
                    return ['status' => self::CHECK_OK, 'lag_seconds' => 0, 'pending' => 0];
                }

                return $this->redisUnavailable();
            }

            if (! is_array($rawGroups)) {
                return $this->redisUnavailable();
            }

            $group = $this->groupEntry($rawGroups);

            if ($group === null) {
                // Группы ещё нет: потребитель не стартовал, но Redis жив.
                return ['status' => self::CHECK_OK, 'lag_seconds' => 0, 'pending' => 0];
            }

            $pending = (int) ($group['pending'] ?? 0);

            return [
                'status' => self::CHECK_OK,
                'lag_seconds' => $this->lagSeconds($connection, $stream, $group, $pending),
                'pending' => $pending,
            ];
        } catch (Throwable) {
            return $this->redisUnavailable();
        }
    }

    /**
     * Описание consumer group сервиса из ответа XINFO GROUPS.
     *
     * @param array<mixed> $groups
     * @return array<string, mixed>|null
     */
    private function groupEntry(array $groups): ?array
    {
        $expected = (string) config('notifications.transport.consumer_group');

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            if (($group['name'] ?? null) === $expected) {
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
            $idle = $this->pendingIdleMilliseconds($connection, $stream, $group);

            return $idle === null ? 0 : intdiv($idle, 1000);
        }

        $unread = $group['lag'] ?? null;

        if (! is_numeric($unread) || (int) $unread < 1) {
            return 0;
        }

        return $this->unreadAgeSeconds($connection, $stream, $group);
    }

    /**
     * Простой самой старой pending-записи в миллисекундах (XPENDING с IDLE).
     *
     * @param array<string, mixed> $group
     */
    private function pendingIdleMilliseconds(Connection $connection, string $stream, array $group): ?int
    {
        $name = (string) ($group['name'] ?? config('notifications.transport.consumer_group'));
        $limit = max(1, (int) config('notifications.transport.batch_size'));

        /** @var mixed $entries */
        $entries = $connection->command('xpending', [$stream, $name, '-', '+', $limit]);

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
     * Возраст первой непрочитанной записи потока по её occurred_at.
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

        $fields = $entry[1];
        $occurredAt = $fields['occurred_at'] ?? null;

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

    /**
     * Имя потока заказов с префиксом окружения (FR-OPS-211).
     */
    private function ordersStream(): string
    {
        return (string) config('notifications.transport.stream_prefix')
            .(string) config('notifications.transport.streams.orders');
    }

    /**
     * @return array{status: string, lag_seconds: int|null, pending: int|null}
     */
    private function redisUnavailable(): array
    {
        return ['status' => self::CHECK_ERROR, 'lag_seconds' => null, 'pending' => null];
    }

    /**
     * Redis ответил, что потока или группы ещё нет.
     *
     * Это не сбой зависимости: потребитель просто не создал поток
     * (Redis отвечает «ERR no such key» на XINFO отсутствующего потока).
     */
    private function isMissingStream(Throwable $exception): bool
    {
        $message = strtoupper($exception->getMessage());

        return str_contains($message, 'NO SUCH KEY') || str_contains($message, 'NOGROUP');
    }

    /**
     * Превышен порог лага из конфигурации (FR-OPS-204).
     */
    private function lagExceeded(?int $lagSeconds): bool
    {
        if ($lagSeconds === null) {
            return false;
        }

        return $lagSeconds > max(0, (int) config('notifications.health.lag_threshold_seconds'));
    }

    /**
     * Превышен порог числа необработанных из конфигурации (FR-OPS-204).
     */
    private function pendingExceeded(?int $pending): bool
    {
        if ($pending === null) {
            return false;
        }

        return $pending > max(0, (int) config('notifications.health.pending_threshold'));
    }
}
