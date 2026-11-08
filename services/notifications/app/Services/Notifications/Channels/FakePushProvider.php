<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\Exceptions\NotificationDeliveryFailed;
use App\Domain\Notifications\Exceptions\NotificationProviderRejected;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\PushMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Фейковый push-провайдер сервиса (FR-NTF-253, FR-NTF-254).
 *
 * Внешней сети нет: сообщение пишется в `push_messages`, откуда его читает
 * страница симулятора. Device-токенов нет — адресация идёт по `recipient_id`.
 * Журнал только на добавление: отправленное сообщение не переписывается.
 *
 * Сбой записи журнала — инфраструктурная ошибка (FR-NTF-256): уведомление
 * уйдёт в `error` и будет повторено по backoff. В сообщении об ошибке только
 * канал, без персональных данных.
 *
 * Детерминированный отказ (FR-NTF-477) настраивается списком получателей:
 * так в тестах проверяется цепочка push → telegram → email без сети
 * (ЧТЗ 12.2, п. 1). Контракт `PushProvider` при этом не меняется.
 */
final class FakePushProvider implements PushProvider
{
    /**
     * Получатели, которым провайдер детерминированно отказывает.
     *
     * @var list<int>
     */
    public array $failRecipients = [];

    public function send(int $notificationId, int $recipientId, NotificationTemplate $template, string $body): void
    {
        if ($this->rejects($recipientId)) {
            throw new NotificationProviderRejected(NotificationChannel::Push);
        }

        try {
            // Запись в своей транзакции: сбой не оставляет соединение
            // в состоянии прерванной транзакции, и вызывающий код может
            // зафиксировать причину в журнале (FR-NTF-256).
            DB::transaction(static function () use ($notificationId, $recipientId, $template, $body): void {
                PushMessage::query()->create([
                    'notification_id' => $notificationId,
                    'recipient_id' => $recipientId,
                    'template' => $template->value,
                    'body' => $body,
                    'sent_at' => now()->utc(),
                ]);
            });
        } catch (Throwable $exception) {
            throw NotificationDeliveryFailed::make(NotificationChannel::Push, $exception);
        }
    }

    /**
     * Детерминированный отказ по получателю: список теста или конфигурации.
     */
    private function rejects(int $recipientId): bool
    {
        if (in_array($recipientId, $this->failRecipients, true)) {
            return true;
        }

        $configured = config('notifications.push.fake_deterministic_fail_recipients', []);

        return is_array($configured) && in_array($recipientId, array_map('intval', $configured), true);
    }
}
