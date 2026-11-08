<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Events\EventType;

/**
 * Политика уведомлений: матрица «событие → роль получателя → каналы» (FR-NTF-241…243).
 *
 * Матрица приходит в домен готовым набором правил, собранным из конфигурации
 * сервиса: домен не читает конфигурацию и не знает про Laravel. События,
 * для которых правил нет (смены курьера), уведомлений не порождают (FR-NTF-243).
 */
final readonly class NotificationPolicy
{
    /**
     * @param list<NotificationRule> $rules правила матрицы
     */
    public function __construct(private array $rules) {}

    /**
     * Все правила матрицы.
     *
     * @return list<NotificationRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Правила для типа события.
     *
     * @return list<NotificationRule>
     */
    public function rulesFor(EventType $eventType): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (NotificationRule $rule): bool => $rule->eventType() === $eventType,
        ));
    }

    /**
     * Роли получателей события с учётом условия «только при наличии назначения».
     *
     * @return list<RecipientRole>
     */
    public function recipients(EventType $eventType, bool $hasAssignment): array
    {
        $roles = [];

        foreach ($this->rulesFor($eventType) as $rule) {
            if (! $rule->isActiveFor($hasAssignment)) {
                continue;
            }

            if (! in_array($rule->role(), $roles, true)) {
                $roles[] = $rule->role();
            }
        }

        return $roles;
    }

    /**
     * Каналы уведомления для роли по событию; пустой список — уведомление не уходит.
     *
     * @return list<NotificationChannel>
     */
    public function channels(EventType $eventType, RecipientRole $role): array
    {
        $channels = [];

        foreach ($this->rulesFor($eventType) as $rule) {
            if ($rule->role() !== $role) {
                continue;
            }

            foreach ($rule->channels() as $channel) {
                if (! in_array($channel, $channels, true)) {
                    $channels[] = $channel;
                }
            }
        }

        return $channels;
    }

    /**
     * Порождает ли событие хотя бы одно уведомление.
     */
    public function notifies(EventType $eventType): bool
    {
        return $this->rulesFor($eventType) !== [];
    }

    /**
     * Типы событий, для которых заданы правила, в порядке матрицы.
     *
     * @return list<EventType>
     */
    public function eventTypes(): array
    {
        $types = [];

        foreach ($this->rules as $rule) {
            if (! in_array($rule->eventType(), $types, true)) {
                $types[] = $rule->eventType();
            }
        }

        return $types;
    }
}
