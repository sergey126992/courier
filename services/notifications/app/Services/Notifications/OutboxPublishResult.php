<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Итог одного прохода публикатора Outbox сервиса (FR-NTF-283).
 *
 * Счётчики нужны команде и журналу: «опубликовано» — сколько строк получили
 * `published_at`, «неудачных» — сколько осталось в Outbox с новой задержкой.
 * Занятая блокировка прохода — не ошибка, а признак параллельного воркера.
 */
final readonly class OutboxPublishResult
{
    public function __construct(
        public int $published = 0,
        public int $failed = 0,
        public bool $lockBusy = false,
    ) {}

    /**
     * Публикацию уже выполняет другой воркер: проход пуст и успешен.
     */
    public static function locked(): self
    {
        return new self(lockBusy: true);
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
