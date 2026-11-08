<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * Итог одного прохода потребления (FR-NTF-204, FR-NTF-265).
 *
 * Счётчики нужны команде, журналу и проверке состояния сервиса: «прочитано» —
 * сколько сообщений выдал транспорт, «обработано» — по скольким зафиксирован
 * эффект, «подтверждено» — по скольким отправлен ack, «ошибок» — сколько
 * осталось в pending и будет выдано повторно после `pending_idle_seconds`.
 */
final readonly class ConsumeResult
{
    /**
     * @param int $created число созданных уведомлений
     * @param bool $degraded транспорт недоступен: проход не выполнен, процесс жив
     */
    public function __construct(
        public int $read = 0,
        public int $processed = 0,
        public int $acknowledged = 0,
        public int $failed = 0,
        public int $invalid = 0,
        public int $created = 0,
        public bool $degraded = false,
    ) {}

    /**
     * Сообщений не было: проход успешен и ничего не изменил.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Транспорт недоступен: ошибка доступа не роняет процесс (FR-NTF-208).
     */
    public static function degraded(): self
    {
        return new self(degraded: true);
    }

    /**
     * Новых сообщений и сообщений на повтор не было.
     */
    public function isEmpty(): bool
    {
        return ! $this->degraded && $this->read === 0;
    }

    /**
     * Есть ошибки обработки: команда сообщает код возврата 1.
     */
    public function hasFailures(): bool
    {
        return $this->failed > 0 || $this->degraded;
    }
}
