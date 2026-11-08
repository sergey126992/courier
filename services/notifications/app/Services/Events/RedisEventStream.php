<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Notifications\NotificationErrorCode;
use App\Services\Events\Contracts\EventStream;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Throwable;

/**
 * Транспорт событий на Redis Streams (FR-NTF-202…210).
 *
 * Чтение — XREADGROUP пачкой, подтверждение — XACK только после фиксации
 * эффекта (FR-NTF-265), возврат «зависших» сообщений — XAUTOCLAIM после
 * `pending_idle_seconds` (FR-NTF-204). Имена потоков, группы, размер пачки
 * и задержка приходят из конфигурации: литералов в коде нет (FR-OPS-211).
 *
 * Недоступность Redis не роняет процесс: методы возвращают null или false,
 * а потребитель сообщает `degraded` (FR-NTF-208). Сообщения в журнал пишутся
 * без тел событий и персональных данных (FR-OPS-202).
 */
final readonly class RedisEventStream implements EventStream
{
    /**
     * Признак существующей consumer group в ответе Redis на XGROUP CREATE.
     */
    private const GROUP_EXISTS_MARKER = 'BUSYGROUP';

    /**
     * @param string $prefix префикс имён потоков: изолирует окружения на общем Redis
     */
    public function __construct(private string $prefix = '') {}

    public function ensureGroup(string $stream, string $group): bool
    {
        try {
            // «0» — группа читает поток с начала: событие не теряется, если
            // группа создана после первой публикации (FR-NTF-208).
            $this->execute(['XGROUP', 'CREATE', $this->key($stream), $group, '0', 'MKSTREAM']);

            return true;
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), self::GROUP_EXISTS_MARKER)) {
                return true;
            }

            $this->logUnavailable('XGROUP CREATE');

            return false;
        }
    }

    public function read(string $stream, string $group, string $consumer, int $limit): ?array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        try {
            $response = $this->execute([
                'XREADGROUP', 'GROUP', $group, $consumer,
                'COUNT', (string) $limit,
                'STREAMS', $this->key($stream), '>',
            ]);
        } catch (Throwable) {
            $this->logUnavailable('XREADGROUP');

            return null;
        }

        return $this->messages($response);
    }

    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit): ?array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        try {
            $response = $this->execute([
                'XAUTOCLAIM', $this->key($stream), $group, $consumer,
                (string) max(0, $idleSeconds), '0-0',
                'COUNT', (string) $limit,
            ]);
        } catch (Throwable) {
            $this->logUnavailable('XAUTOCLAIM');

            return null;
        }

        // Ответ XAUTOCLAIM: [следующий идентификатор, список записей, удалённые].
        if (! is_array($response) || ! isset($response[1]) || ! is_array($response[1])) {
            return [];
        }

        return $this->messages([[$this->key($stream), $response[1]]]);
    }

    public function acknowledge(string $stream, string $group, string $messageId): bool
    {
        try {
            $this->execute(['XACK', $this->key($stream), $group, $messageId]);

            return true;
        } catch (Throwable) {
            $this->logUnavailable('XACK');

            Log::error('Сообщение не подтверждено в consumer group.', [
                'error_code' => NotificationErrorCode::ACK_FAILED,
                'event_id' => $messageId,
            ]);

            return false;
        }
    }

    public function publish(string $stream, array $fields): bool
    {
        $arguments = ['XADD', $this->key($stream), '*'];

        foreach ($fields as $name => $value) {
            $arguments[] = $name;
            $arguments[] = $value;
        }

        try {
            $this->execute($arguments);

            return true;
        } catch (Throwable) {
            Log::warning('Обратное событие не передано в транспорт; строка остаётся в Outbox.', [
                'error_code' => NotificationErrorCode::TRANSPORT_UNAVAILABLE,
            ]);

            return false;
        }
    }

    /**
     * Разбирает ответ XREADGROUP/XAUTOCLAIM в список сообщений.
     *
     * Сырой ответ Predis отдаёт пары «поток → записи», поэтому записи
     * читаются как [идентификатор, поля] и не разбираются по имени потока.
     *
     * @param mixed $response ответ Redis
     * @return list<StreamMessage>
     */
    private function messages(mixed $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $messages = [];

        foreach ($response as $streamReply) {
            if (! is_array($streamReply)) {
                continue;
            }

            $entries = $streamReply[1] ?? null;

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $id = $entry[0] ?? null;
                $fields = $entry[1] ?? null;

                if (! is_scalar($id) || ! is_array($fields)) {
                    continue;
                }

                $messages[] = new StreamMessage((string) $id, $this->normalizeFields($fields));
            }
        }

        return $messages;
    }

    /**
     * Приводит поля записи к карте «имя → значение».
     *
     * Сырой ответ Predis отдаёт поля плоским списком «имя, значение, …»;
     * разобранный ответ клиента приходит картой.
     *
     * @param array<int|string, mixed> $fields
     * @return array<string, string>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        if (! array_is_list($fields)) {
            foreach ($fields as $name => $value) {
                $normalized[(string) $name] = is_scalar($value) ? (string) $value : '';
            }

            return $normalized;
        }

        for ($index = 0; $index + 1 < count($fields); $index += 2) {
            $name = $fields[$index];

            if (! is_scalar($name)) {
                continue;
            }

            $value = $fields[$index + 1];
            $normalized[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized;
    }

    /**
     * Имя ключа потока с префиксом окружения (FR-OPS-211).
     */
    private function key(string $stream): string
    {
        return $this->prefix.$stream;
    }

    /**
     * Выполняет команду Redis и возвращает сырой ответ.
     *
     * executeRaw не применяет глобальный префикс подключения: имена потоков
     * полностью задаются ключами конфигурации.
     *
     * @param list<string> $arguments
     */
    private function execute(array $arguments): mixed
    {
        /** @var Connection $connection */
        $connection = Redis::connection();

        return $connection->command('executeRaw', [$arguments]);
    }

    /**
     * Журнал недоступности Redis: только имя операции и код причины.
     */
    private function logUnavailable(string $command): void
    {
        Log::warning('Транспорт событий недоступен ('.$command.'); проход потребления пропущен.', [
            'error_code' => NotificationErrorCode::TRANSPORT_UNAVAILABLE,
        ]);
    }
}
