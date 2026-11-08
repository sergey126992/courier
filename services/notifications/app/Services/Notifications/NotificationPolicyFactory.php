<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventType;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\NotificationRule;
use App\Domain\Notifications\RecipientRole;
use InvalidArgumentException;

/**
 * Собирает доменную политику из конфигурации сервиса (FR-NTF-241…245).
 *
 * Матрица «событие → роль получателя → каналы» и признак «только при наличии
 * назначения» живут в config/notifications.php сервиса — это единственное
 * место правил M7. Домен не читает конфигурацию: он получает готовые правила
 * с перечислениями. Каналы, выключенные в конфигурации, отбрасываются без
 * ошибки (FR-NTF-245); правило без каналов в политику не попадает.
 */
final class NotificationPolicyFactory
{
    /**
     * Политика по текущей конфигурации сервиса.
     *
     * @throws InvalidArgumentException неизвестное событие, роль или канал в матрице
     */
    public function make(): NotificationPolicy
    {
        /** @var array<string, array<string, list<string>>> $matrix */
        $matrix = (array) config('notifications.policy', []);

        /** @var array<string, list<string>> $conditional */
        $conditional = (array) config('notifications.requires_assignment', []);

        $enabled = $this->enabledChannels();

        $rules = [];

        foreach ($matrix as $eventTypeValue => $recipients) {
            $eventType = EventType::tryFrom((string) $eventTypeValue);

            if ($eventType === null) {
                throw new InvalidArgumentException(sprintf(
                    'Тип события «%s» в матрице уведомлений неизвестен.',
                    (string) $eventTypeValue,
                ));
            }

            foreach ((array) $recipients as $roleValue => $channelValues) {
                $role = RecipientRole::tryFrom((string) $roleValue);

                if ($role === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Роль получателя «%s» в матрице уведомлений неизвестна.',
                        (string) $roleValue,
                    ));
                }

                $channels = [];

                foreach ((array) $channelValues as $channelValue) {
                    $channel = NotificationChannel::tryFrom((string) $channelValue);

                    if ($channel === null) {
                        throw new InvalidArgumentException(sprintf(
                            'Канал «%s» в матрице уведомлений неизвестен.',
                            (string) $channelValue,
                        ));
                    }

                    if (in_array($channel->value, $enabled, true)) {
                        $channels[] = $channel;
                    }
                }

                if ($channels === []) {
                    continue;
                }

                /** @var list<string> $conditionalRoles */
                $conditionalRoles = (array) ($conditional[$eventType->value] ?? []);

                $rules[] = new NotificationRule(
                    eventType: $eventType,
                    role: $role,
                    channels: $channels,
                    requiresAssignment: in_array($role->value, $conditionalRoles, true),
                );
            }
        }

        return new NotificationPolicy($rules);
    }

    /**
     * Включённые каналы доставки в порядке конфигурации.
     *
     * @return list<string>
     */
    public function enabledChannels(): array
    {
        /** @var list<mixed> $values */
        $values = (array) config('notifications.enabled_channels', []);

        $channels = [];

        foreach ($values as $value) {
            $channel = trim((string) $value);

            if ($channel !== '') {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
