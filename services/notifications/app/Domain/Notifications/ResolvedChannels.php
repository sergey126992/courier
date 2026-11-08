<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Результат подбора каналов для получателя (FR-NTF-471…476).
 *
 * Значение собирает резолвер предпочтений: список доступных каналов уже
 * упорядочен по приоритету. Отдельные состояния выражают три исхода, которые
 * потребитель события различает без обращения к базе:
 *
 * - `available` — цепочка непуста, строки `created` создаются по каналам;
 * - `disabledByUser` — получатель отключил тип события (`channels: []`),
 *   строк и обратного события нет (FR-NTF-456);
 * - `unavailable` — каналы выбраны, но ни один недоступен заранее: нужна
 *   одна строка `failed` с причиной и обратное событие (FR-NTF-476);
 * - `nothing` — выбирать нечего (матрица роли пуста), строк нет.
 *
 * Домен не знает ни про конфигурацию, ни про базу: решение принимает слой
 * сервисов, а значение только переносит его.
 */
final readonly class ResolvedChannels
{
    /**
     * @param list<NotificationChannel> $channels доступные каналы в порядке приоритета
     * @param NotificationChannel|null $unavailableChannel канал верхнего приоритета из выбранных
     * @param string|null $unavailableReason причина недоступности всей цепочки
     * @param bool $disabledByUser получатель отключил тип события
     * @param QuietHours|null $quietHours окно тихих часов получателя
     */
    private function __construct(
        private array $channels,
        private ?NotificationChannel $unavailableChannel,
        private ?string $unavailableReason,
        private bool $disabledByUser,
        private ?QuietHours $quietHours = null,
    ) {}

    /**
     * Доступные каналы в порядке приоритета.
     *
     * @param list<NotificationChannel> $channels
     */
    public static function available(array $channels, ?QuietHours $quietHours = null): self
    {
        return new self($channels, null, null, false, $quietHours);
    }

    /**
     * Тип события отключён получателем: уведомлять не нужно (FR-NTF-456).
     */
    public static function disabledByUser(): self
    {
        return new self([], null, null, true);
    }

    /**
     * Каналы выбраны, но все недоступны заранее (FR-NTF-474, FR-NTF-476).
     */
    public static function unavailable(?NotificationChannel $channel, string $reason): self
    {
        return new self([], $channel, $reason, false);
    }

    /**
     * Каналов для получателя нет и выбирать было нечего.
     */
    public static function nothing(): self
    {
        return new self([], null, null, false);
    }

    /**
     * Доступные каналы в порядке приоритета.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Цепочка пуста: доступных каналов нет.
     */
    public function isEmpty(): bool
    {
        return $this->channels === [];
    }

    /**
     * Тип события отключён получателем: строк и обратного события нет.
     */
    public function isDisabledByUser(): bool
    {
        return $this->disabledByUser;
    }

    /**
     * Причина недоступности всей цепочки (код ошибки сервиса).
     */
    public function unavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    /**
     * Окно тихих часов получателя; null — окно не задано.
     */
    public function quietHours(): ?QuietHours
    {
        return $this->quietHours;
    }

    /**
     * Канал верхнего приоритета из выбранных: для строки `failed` пустой цепочки.
     */
    public function unavailableChannel(): ?NotificationChannel
    {
        return $this->unavailableChannel;
    }
}
