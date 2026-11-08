<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\NotificationChannel;
use App\Models\ChannelBinding;
use App\Models\RecipientContact;
use App\Services\Notifications\NotificationPolicyFactory;

/**
 * Заранее известная доступность каналов получателя (FR-NTF-440, FR-NTF-473, FR-NTF-474).
 *
 * Проверка выполняется до создания строк журнала: канал, отключённый
 * в `enabled_channels` или недоступный заранее, строки не создаёт.
 *
 * - email — есть контакт получателя (адрес не покидает сервис, FR-OPS-202);
 * - push — доступен при включённом канале: реальных токенов устройств
 *   в M12 нет (ЧТЗ 12.2, п. 1);
 * - telegram — активная привязка `verified_at` заполнен и `revoked_at` пуст.
 *
 * Адреса и `chat_id` в ответах не появляются: наружу отдаётся только факт
 * доступности (FR-NTF-419).
 */
final class ChannelAvailability
{
    public function __construct(private readonly NotificationPolicyFactory $policyFactory) {}

    /**
     * Доступные каналы из кандидатов в исходном порядке.
     *
     * @param list<NotificationChannel> $candidates
     * @return list<NotificationChannel>
     */
    public function filterAvailable(int $userId, array $candidates): array
    {
        $enabled = $this->enabledChannels();

        $checkEmail = in_array(NotificationChannel::Email, $candidates, true)
            && in_array(NotificationChannel::Email, $enabled, true);
        $checkTelegram = in_array(NotificationChannel::Telegram, $candidates, true)
            && in_array(NotificationChannel::Telegram, $enabled, true);

        // Каждый контакт и каждая привязка читаются не более одного раза
        // на получателя, даже если кандидатов несколько.
        $hasEmail = $checkEmail && $this->hasEmailContact($userId);
        $hasTelegram = $checkTelegram && $this->hasActiveTelegramBinding($userId);

        $available = [];

        foreach ($candidates as $channel) {
            $isAvailable = match ($channel) {
                NotificationChannel::Email => $hasEmail,
                NotificationChannel::Push => in_array(NotificationChannel::Push, $enabled, true),
                NotificationChannel::Telegram => $hasTelegram,
            };

            if ($isAvailable) {
                $available[] = $channel;
            }
        }

        return $available;
    }

    /**
     * Включён ли канал в конфигурации сервиса (FR-NTF-245, FR-NTF-473).
     */
    public function isEnabled(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->enabledChannels(), true);
    }

    /**
     * Включённые каналы доставки в порядке конфигурации.
     *
     * @return list<NotificationChannel>
     */
    public function enabledChannels(): array
    {
        $channels = [];

        foreach ($this->policyFactory->enabledChannels() as $value) {
            $channel = NotificationChannel::tryFrom($value);

            if ($channel !== null && ! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Есть ли у получателя контакт email.
     */
    private function hasEmailContact(int $userId): bool
    {
        return RecipientContact::query()
            ->whereKey($userId)
            ->where('email', '<>', '')
            ->exists();
    }

    /**
     * Есть ли активная подтверждённая привязка Telegram (ЧТЗ 5.2).
     */
    private function hasActiveTelegramBinding(int $userId): bool
    {
        return ChannelBinding::query()
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Telegram->value)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->exists();
    }
}
