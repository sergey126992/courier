<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventType;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use App\Logging\TechnicalLogger;
use App\Services\Events\EventInbox;
use Illuminate\Support\Facades\DB;

/**
 * Контакты получателей из потока `events.contacts` (FR-NTF-231…236).
 *
 * Эффект идемпотентен: строка `recipient_contacts` обновляется по `user_id`
 * (первичный ключ уникален), поэтому повтор события и повторная доставка
 * не создают вторую строку, а изменение email применяется к ещё не
 * отправленным уведомлениям, которые читают адрес в момент отправки
 * (FR-NTF-233).
 *
 * Отметка «сообщение обработано» пишется в той же транзакции через Inbox,
 * поэтому сбой эффекта откатывает и её: повторная выдача потока снова
 * применит контакт (FR-NTF-261, FR-NTF-265).
 *
 * Адрес — персональные данные: он сохраняется в `recipient_contacts`
 * и `channel_bindings`, но не пишется в журнал и не попадает в сообщения
 * об ошибках (FR-NTF-232, FR-NTF-235).
 */
final class RecipientContactSync
{
    public function __construct(
        private readonly EventInbox $inbox,
        private readonly TechnicalLogger $logger,
    ) {}

    /**
     * Применяет событие контакта и возвращает число обновлённых контактов.
     */
    public function process(string $stream, string $consumer, EventEnvelope $event): int
    {
        if ($event->eventType !== EventType::UserContactChanged) {
            return 0;
        }

        $userId = $event->payloadInt('user_id');
        $email = trim((string) $event->payloadString('email'));

        if ($userId <= 0 || $email === '') {
            // Схема такие события не пропускает; защита от неполного контракта.
            $this->logger->warning('Контакт получателя без идентификатора или адреса пропущен.', [
                'correlation_id' => $event->correlationId,
                'event_id' => $event->eventId(),
                'error_code' => NotificationErrorCode::CONTRACT_INVALID,
            ]);

            return 0;
        }

        $locale = $this->locale($event);
        $eventId = $event->eventId();
        $updated = 0;

        $this->inbox->process($stream, $consumer, $event->messageId, function () use ($userId, $email, $locale, $eventId, &$updated): void {
            $now = now()->utc();

            DB::table('recipient_contacts')->upsert([
                [
                    'user_id' => $userId,
                    'email' => $email,
                    'locale' => $locale,
                    'source_event_id' => $eventId,
                    'created_at' => $now->format('Y-m-d H:i:sP'),
                    'updated_at' => $now->format('Y-m-d H:i:sP'),
                ],
            ], ['user_id'], ['email', 'locale', 'source_event_id', 'updated_at']);

            $this->bindEmail($userId, $email, $now->format('Y-m-d H:i:sP'));

            $updated++;
        });

        return $updated;
    }

    /**
     * Активная привязка email как подготовка к M12 (FR-NTF-233).
     *
     * Частичный уникальный индекс по строкам без `revoked_at` не выражается
     * целями upsert, поэтому вставка идёт через ON CONFLICT DO NOTHING,
     * а при конфликте адрес обновляется у активной строки.
     */
    private function bindEmail(int $userId, string $email, string $now): void
    {
        $inserted = DB::table('channel_bindings')->insertOrIgnore([
            'user_id' => $userId,
            'channel' => NotificationChannel::Email->value,
            'address' => $email,
            'verified_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 0) {
            return;
        }

        DB::table('channel_bindings')
            ->where('user_id', $userId)
            ->where('channel', NotificationChannel::Email->value)
            ->whereNull('revoked_at')
            ->update(['address' => $email, 'updated_at' => $now]);
    }

    /**
     * Локаль контакта: значение события с фолбэком на язык текстов сервиса.
     */
    private function locale(EventEnvelope $event): string
    {
        $locale = trim((string) $event->payloadString('locale'));

        if ($locale === '') {
            $locale = trim((string) config('notifications.locales.fallback'));
        }

        return mb_substr($locale === '' ? 'ru' : $locale, 0, 8);
    }
}
