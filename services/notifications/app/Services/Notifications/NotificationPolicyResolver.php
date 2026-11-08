<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventType;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\QuietHours;
use App\Domain\Notifications\RecipientRole;
use App\Domain\Notifications\ResolvedChannels;
use App\Models\NotificationPreference;
use App\Services\Notifications\Channels\ChannelAvailability;
use App\Services\Notifications\Preferences\PreferenceRepository;

/**
 * Резолвер каналов уведомления для получателя (FR-NTF-471…474).
 *
 * Сохранённая строка предпочтения авторитетна для типа события: набор
 * каналов пользователя может включать `telegram`, даже если матрица M11 его
 * не содержит. Если строки нет, действует матрица M11 — каналы берутся
 * для конкретной роли получателя, а не объединением по всем ролям
 * (FR-NTF-459). `channels: []` у некритичного события означает «не
 * уведомлять» (FR-NTF-456).
 *
 * Каналы вне `enabled_channels` и недоступные заранее (нет активной
 * привязки Telegram, нет контакта email) отбрасываются без ошибки
 * (FR-NTF-473, FR-NTF-474). Итоговый список упорядочен по
 * `notifications.preferences.channel_priority`, email всегда последний
 * (FR-NTF-472).
 *
 * `resolve(array $preferences = [])` сохраняется как политика по умолчанию:
 * на M11 пользовательских настроек нет, и вызывающий получает матрицу
 * без изменений.
 */
final class NotificationPolicyResolver
{
    public function __construct(
        private readonly NotificationPolicyFactory $factory,
        private readonly PreferenceRepository $preferences,
        private readonly ChannelAvailability $availability,
    ) {}

    /**
     * Политика для набора предпочтений; пустой набор — политика по умолчанию.
     *
     * @param array<string, mixed> $preferences предпочтения получателя (M12), на M11 всегда пусты
     */
    public function resolve(array $preferences = []): NotificationPolicy
    {
        // M12 применит предпочтения поверх правил; на M11 набор всегда пуст,
        // а непустой набор не должен менять правила, которых ещё нет в данных.
        return $this->factory->make();
    }

    /**
     * Каналы уведомления для получателя события (FR-NTF-471…476).
     */
    public function resolveFor(
        int $userId,
        EventType $eventType,
        RecipientRole $role,
        bool $hasAssignment,
    ): ResolvedChannels {
        // Один запрос на получателя: строки предпочтений читаются набором
        // и дальше используются без дополнительных обращений (FR-NTF-475).
        $saved = $this->preferences->savedFor($userId)[$eventType->value] ?? null;
        $quietHours = $saved === null
            ? null
            : QuietHours::fromStored($saved->quiet_hours, $this->defaultTimezone());

        if ($saved !== null) {
            $selected = $this->savedChannels($saved);

            if ($selected === []) {
                return ResolvedChannels::disabledByUser();
            }
        } else {
            $selected = $this->matrixChannels($eventType, $role, $hasAssignment);

            if ($selected === []) {
                return ResolvedChannels::nothing();
            }
        }

        $chain = $this->enabledOnly($selected);

        if ($chain === []) {
            // Все выбранные каналы выключены в окружении: цепочка пуста,
            // но канал верхнего приоритета всё равно известен (FR-NTF-476).
            return ResolvedChannels::unavailable(
                $selected[0] ?? null,
                NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE,
            );
        }

        $available = $this->availability->filterAvailable($userId, $chain);

        if ($available === []) {
            return ResolvedChannels::unavailable(
                $chain[0],
                NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE,
            );
        }

        return ResolvedChannels::available($this->order($available), $quietHours);
    }

    /**
     * Каналы сохранённой строки предпочтений в порядке хранения.
     *
     * @return list<NotificationChannel>
     */
    private function savedChannels(NotificationPreference $model): array
    {
        $channels = [];

        foreach ((array) $model->channels as $value) {
            if (! is_string($value)) {
                continue;
            }

            $channel = NotificationChannel::tryFrom($value);

            if ($channel !== null && ! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Каналы матрицы M11 для конкретной роли получателя (FR-NTF-459).
     *
     * Условие «только при наличии назначения» проверяется по правилу:
     * без назначения правило роли не даёт каналов.
     *
     * @return list<NotificationChannel>
     */
    private function matrixChannels(EventType $eventType, RecipientRole $role, bool $hasAssignment): array
    {
        $policy = $this->factory->make();
        $channels = [];

        foreach ($policy->rulesFor($eventType) as $rule) {
            if ($rule->role() !== $role || ! $rule->isActiveFor($hasAssignment)) {
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
     * Каналы, включённые в окружении; выключенные отбрасываются без ошибки.
     *
     * @param list<NotificationChannel> $channels
     * @return list<NotificationChannel>
     */
    private function enabledOnly(array $channels): array
    {
        $enabled = $this->availability->enabledChannels();

        return array_values(array_filter(
            $channels,
            static fn (NotificationChannel $channel): bool => in_array($channel, $enabled, true),
        ));
    }

    /**
     * Таймзона окна тихих часов по умолчанию (FR-NTF-491).
     */
    private function defaultTimezone(): string
    {
        return (string) config('notifications.preferences.default_timezone', '');
    }

    /**
     * Порядок каналов по `preferences.channel_priority`; email всегда последний.
     *
     * @param list<NotificationChannel> $channels
     * @return list<NotificationChannel>
     */
    private function order(array $channels): array
    {
        $priority = [];

        foreach ((array) config('notifications.preferences.channel_priority', []) as $value) {
            $channel = NotificationChannel::tryFrom((string) $value);

            if ($channel === null || $channel === NotificationChannel::Email) {
                continue;
            }

            if (! in_array($channel, $priority, true)) {
                $priority[] = $channel;
            }
        }

        $ordered = [];

        foreach ($priority as $channel) {
            if (in_array($channel, $channels, true)) {
                $ordered[] = $channel;
            }
        }

        // Каналы, которых нет в конфигурации приоритета, не теряются:
        // они идут после заданных, но до email.
        foreach ($channels as $channel) {
            if ($channel === NotificationChannel::Email) {
                continue;
            }

            if (! in_array($channel, $ordered, true)) {
                $ordered[] = $channel;
            }
        }

        if (in_array(NotificationChannel::Email, $channels, true)) {
            $ordered[] = NotificationChannel::Email;
        }

        return $ordered;
    }
}
