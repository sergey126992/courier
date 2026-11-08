<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Events\EventType;

/**
 * Правило матрицы уведомлений: событие, роль получателя и каналы (FR-NTF-241).
 *
 * Признак «требует назначения» выражает условие «курьеру — только если у заказа
 * было назначение» без обращения к базе из домена: решение принимает
 * вызывающий слой, передав в политику, найден ли получатель-курьер.
 */
final readonly class NotificationRule
{
    /**
     * @param list<NotificationChannel> $channels каналы уведомления
     * @param bool $requiresAssignment уведомление уходит только при наличии назначения
     */
    public function __construct(
        private EventType $eventType,
        private RecipientRole $role,
        private array $channels,
        private bool $requiresAssignment = false,
    ) {}

    /**
     * Тип события, к которому относится правило.
     */
    public function eventType(): EventType
    {
        return $this->eventType;
    }

    /**
     * Роль получателя правила.
     */
    public function role(): RecipientRole
    {
        return $this->role;
    }

    /**
     * Каналы уведомления в порядке конфигурации.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Условие правила: уведомление уходит только при наличии назначения.
     */
    public function requiresAssignment(): bool
    {
        return $this->requiresAssignment;
    }

    /**
     * Применимо ли правило при известном признаке наличия назначения.
     */
    public function isActiveFor(bool $hasAssignment): bool
    {
        return ! $this->requiresAssignment || $hasAssignment;
    }
}
