<?php

declare(strict_types=1);

namespace App\Services\Events;

use InvalidArgumentException;

/**
 * Выбор адаптера транспорта событий сервиса по конфигурации (FR-NTF-545).
 *
 * Адаптеры: Redis Streams (значение по умолчанию) и RabbitMQ (опция под
 * профилем `broker`). Неизвестное значение останавливает процесс ошибкой
 * конфигурации при старте, а не тихим переходом в другой транспорт
 * (ЧТЗ 9.6, п. 1, FR-NTF-545).
 */
final class TransportDriver
{
    /**
     * Адаптер Redis Streams — рабочий транспорт сервиса (FR-NTF-202).
     */
    public const REDIS = 'redis';

    /**
     * Опциональный брокер RabbitMQ (FR-NTF-541…552).
     */
    public const RABBITMQ = 'rabbitmq';

    /**
     * Допустимые значения `notifications.transport.driver`.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return [self::REDIS, self::RABBITMQ];
    }

    /**
     * Приводит значение конфигурации к имени поддерживаемого адаптера.
     *
     * @throws InvalidArgumentException значение не поддерживается или адаптер не подключён
     */
    public static function resolve(string $value): string
    {
        $driver = strtolower(trim($value));

        return match ($driver) {
            self::REDIS => self::REDIS,
            self::RABBITMQ => self::RABBITMQ,
            default => throw new InvalidArgumentException(sprintf(
                'Транспорт событий «%s» не поддерживается (notifications.transport.driver).',
                $driver,
            )),
        };
    }
}
