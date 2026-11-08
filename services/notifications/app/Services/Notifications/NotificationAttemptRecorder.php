<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationAttempt;

/**
 * Запись попытки отправки в журнал (FR-NTF-273, ЧТЗ 7, п. 6).
 *
 * Таблица `notification_attempts` только на добавление: строка фиксируется
 * один раз и не переписывается. Номер попытки — тот же, что учтён в
 * `notifications.attempts`, поэтому уникальный индекс
 * `(notification_id, attempt)` защищает от двойной записи.
 */
final class NotificationAttemptRecorder
{
    public function __construct(private readonly AttemptMessageSanitizer $sanitizer) {}

    /**
     * Пишет одну строку журнала попыток без персональных данных.
     */
    public function record(
        Notification $notification,
        int $attempt,
        NotificationStatus $status,
        ?string $errorClass,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $providerCode = null,
    ): void {
        $message = $this->sanitizer->sanitize($errorMessage);

        // Момент пишется строкой в UTC: колонка timestamptz не должна
        // зависеть от часового пояса приложения (ЧТЗ 7, п. 5).
        NotificationAttempt::query()->insert([
            'notification_id' => (int) $notification->getKey(),
            'attempt' => $attempt,
            'status' => $status->value,
            'error_class' => $errorClass,
            'error_code' => $errorCode,
            'error_message' => $message === '' ? null : $message,
            'provider_code' => $providerCode,
            'created_at' => now()->utc()->format('Y-m-d H:i:sP'),
        ]);
    }
}
