<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * Итог одного прохода потребления (FR-NTF-201, FR-NTF-204).
 *
 * Счётчики нужны вызывающему коду, журналу и проверке состояния сервиса:
 * «прочитано» — сколько сообщений выдал транспорт, «подтверждено» — по скольким
 * зафиксирован эффект и отправлен ack, «ошибок» — сколько осталось в pending
 * и будет выдано повторно после pending_idle_seconds.
 */
final readonly class ConsumeResult
{
    /**
     * @param list<string> $messageIds идентификаторы прочитанных сообщений
     * @param int $invalid сообщения с неизвестным или некорректным контрактом
     * @param bool $degraded транспорт недоступен: проход не выполнен, процесс жив
     */
    public function __construct(
        public int $read = 0,
        public int $processed = 0,
        public int $acknowledged = 0,
        public int $failed = 0,
        public int $invalid = 0,
        public array $messageIds = [],
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

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
