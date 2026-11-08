<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * Итог одного прохода публикатора (ЧТЗ 8.2).
 *
 * Код возврата команды выводится из этих счётчиков, а занятая блокировка
 * не считается ошибкой: работу выполняет конкурирующий воркер.
 */
final readonly class PublishResult
{
    public function __construct(
        public int $published,
        public int $failed,
        public bool $lockBusy = false,
    ) {}

    /**
     * Проход не выполнялся: блокировку держит другой воркер (FR-NTF-104).
     */
    public static function locked(): self
    {
        return new self(0, 0, true);
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
