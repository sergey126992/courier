<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\Exceptions\NotificationBusinessException;
use App\Domain\Notifications\Exceptions\NotificationInfrastructureException;
use App\Domain\Notifications\Exceptions\PushRateLimited;
use App\Domain\Notifications\Exceptions\TelegramRateLimited;
use App\Domain\Notifications\NotificationAttemptClass;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Logging\TechnicalLogger;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Одна попытка отправки уведомления (FR-NTF-251…256, 271…274, ЧТЗ 5.3).
 *
 * Слой отделён от потребителя и от команды: он принимает строку журнала
 * и переводит её по состояниям `created`/`error` → `queued` → `sent` либо
 * `error`/`failed`. Каждая попытка пишется в `notification_attempts`
 * (FR-NTF-273), поэтому строки журнала попыток не переписываются.
 *
 * Классы ошибок разделены (FR-NTF-271): бизнес-причина (`failed`, без
 * повтора) и программная ошибка (`Error`, `failed`, без повтора) повтором
 * не лечатся; инфраструктурный сбой даёт `error` и повтор по backoff.
 * Исчерпание `notifications.sender.max_attempts` переводит запись в
 * `failed` с сохранённой причиной (FR-NTF-274). Персональных данных
 * в коде и в сообщениях попыток нет (FR-OPS-202).
 */
final class NotificationSender
{
    public function __construct(
        private readonly ChannelSender $channels,
        private readonly NotificationAttemptRecorder $attempts,
        private readonly BackoffSchedule $backoff,
        private readonly NotificationEventOutbox $outbox,
        private readonly TechnicalLogger $logger,
        private readonly ChannelFallback $fallback,
        private readonly DlqEntryWriter $dlq,
        private readonly NotificationCounters $counters,
    ) {}

    /**
     * Отправляет уведомление по каналу и возвращает итоговое состояние.
     */
    public function send(Notification $notification): NotificationStatus
    {
        if ($notification->status->isTerminal()) {
            // Отправленное и необработанное автоматически не повторяются.
            return $notification->status;
        }

        $attempt = $this->begin($notification);
        $channel = (string) $notification->channel;

        if ($attempt > 1) {
            // Счётчик повторов растёт по факту повторной попытки (FR-OPS-502).
            $this->counters->recordRetried($channel);
        }

        $startedAt = hrtime(true);

        try {
            $this->channels->send($notification);
        } catch (NotificationBusinessException $exception) {
            $status = $this->fail(
                $notification,
                $attempt,
                NotificationAttemptClass::BUSINESS,
                $exception->errorCode(),
                $exception,
            );

            // Детерминированный отказ канала — штатное замещение следующим
            // каналом цепочки; форма finalize() не меняется (FR-NTF-477, 480).
            // Инфраструктурные сбои сюда не попадают: их лечит повтор.
            $this->fallback->handle($notification, $exception->errorCode());

            return $status;
        } catch (NotificationInfrastructureException $exception) {
            return $this->retryOrFail(
                $notification,
                $attempt,
                $exception->errorCode(),
                $exception,
                $exception->providerCode(),
            );
        } catch (Throwable $exception) {
            if ($exception instanceof \Error) {
                // Программная ошибка: повтор бессмысленен, нужен разбор (FR-NTF-271).
                return $this->fail(
                    $notification,
                    $attempt,
                    NotificationAttemptClass::PROGRAM,
                    NotificationErrorCode::PROGRAM_ERROR,
                    $exception,
                );
            }

            // Непредвиденный сбой канала считаем инфраструктурным: повтор
            // безопасен, потому что строка журнала та же (FR-NTF-271).
            return $this->retryOrFail(
                $notification,
                $attempt,
                NotificationErrorCode::SEND_FAILED,
                $exception,
                null,
            );
        } finally {
            // Длительность — фактическое время попытки, включая повтор (FR-OPS-503).
            $this->counters->observeDuration($channel, (hrtime(true) - $startedAt) / 1.0e9);
        }

        $this->finalize($notification, $attempt, NotificationStatus::Sent, null, null, null);

        return NotificationStatus::Sent;
    }

    /**
     * Начало попытки: уведомление в очереди, счётчик попыток увеличен.
     *
     * @return int номер текущей попытки (нумерация с единицы)
     */
    private function begin(Notification $notification): int
    {
        $attempt = $notification->attempts + 1;
        $now = now()->utc()->format('Y-m-d H:i:sP');

        DB::table('notifications')
            ->where('id', $notification->getKey())
            ->update([
                'status' => NotificationStatus::Queued->value,
                'attempts' => $attempt,
                // Строка выбрана — значит плановая задержка уже прошла:
                // запрет не переносится на новую попытку (FR-NTF-436).
                'next_attempt_at' => null,
                'updated_at' => $now,
            ]);

        $notification->refresh();

        return $attempt;
    }

    /**
     * Бизнес- или программная ошибка: сразу `failed`, без повтора.
     */
    private function fail(
        Notification $notification,
        int $attempt,
        string $errorClass,
        string $errorCode,
        Throwable $exception,
    ): NotificationStatus {
        $this->finalize($notification, $attempt, NotificationStatus::Failed, $errorClass, $errorCode, $exception->getMessage());

        return NotificationStatus::Failed;
    }

    /**
     * Инфраструктурная ошибка: `error` и повтор, либо `failed` при исчерпании.
     */
    private function retryOrFail(
        Notification $notification,
        int $attempt,
        string $errorCode,
        Throwable $exception,
        ?string $providerCode,
    ): NotificationStatus {
        $status = $attempt >= $this->backoff->maxAttempts()
            ? NotificationStatus::Failed
            : NotificationStatus::Error;

        // Лимит провайдера задаёт нижнюю границу повтора: раньше `retry_after`
        // (Telegram) или `Retry-After` (FCM) строку не берут (FR-NTF-436,
        // FR-NTF-523). Для исчерпанных попыток запрет не нужен — строка уже
        // терминальна.
        $retryAfterSeconds = match (true) {
            $exception instanceof TelegramRateLimited => $exception->retryAfterSeconds(),
            $exception instanceof PushRateLimited => $exception->retryAfterSeconds(),
            default => null,
        };

        $nextAttemptAt = $status === NotificationStatus::Error && $retryAfterSeconds !== null
            ? now()->utc()->addSeconds($retryAfterSeconds)
            : null;

        $this->finalize(
            $notification,
            $attempt,
            $status,
            NotificationAttemptClass::INFRASTRUCTURE,
            $errorCode,
            $exception->getMessage(),
            $providerCode,
            $nextAttemptAt,
        );

        return $status;
    }

    /**
     * Итог попытки в одной транзакции: статус, строка попытки и обратное событие.
     *
     * Терминальный статус (`sent`/`failed`) и запись в Outbox сервиса
     * фиксируются вместе (FR-NTF-281): сбой записи события откатывает и
     * статус, поэтому «отправлено без события в Outbox» не появится.
     */
    private function finalize(
        Notification $notification,
        int $attempt,
        NotificationStatus $status,
        ?string $errorClass,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $providerCode = null,
        ?Carbon $nextAttemptAt = null,
    ): NotificationStatus {
        DB::transaction(function () use ($notification, $attempt, $status, $errorClass, $errorCode, $errorMessage, $providerCode, $nextAttemptAt): void {
            $this->finish($notification, $status, $errorCode, $nextAttemptAt);
            $this->attempts->record(
                $notification,
                $attempt,
                $status,
                $errorClass,
                $errorCode,
                $errorMessage,
                $providerCode,
            );
            $this->outbox->enqueue($notification, $status, $attempt, $errorCode);
            // Запись DLQ — третий эффект той же транзакции: статус, событие
            // и снимок отказа фиксируются вместе (FR-NTF-501, FR-NTF-507).
            $this->dlq->record($notification, $status, $attempt, $errorClass, $errorCode);
        });

        // Итог попытки в журнале: только технические поля; текст ошибки
        // провайдера остаётся в журнале попыток, а не в stderr (FR-OPS-202).
        $fields = [
            'correlation_id' => $notification->correlation_id,
            'notification_id' => (int) $notification->getKey(),
            'order_id' => $notification->order_id,
            'recipient_id' => (int) $notification->recipient_id,
            'channel' => (string) $notification->channel,
            'status' => $status->value,
            'attempt' => $attempt,
            'error_code' => $errorCode,
        ];

        if ($status === NotificationStatus::Sent) {
            $this->logger->info('Попытка отправки уведомления завершена.', $fields);
        } else {
            $this->logger->warning('Попытка отправки уведомления завершена.', $fields);
        }

        // Терминальный итог попытки — единственная точка счётчиков M13:
        // отправлено и окончательно не отправлено считаются здесь (FR-OPS-502).
        if ($status === NotificationStatus::Sent) {
            $this->counters->recordSent((string) $notification->channel);
        } elseif ($status === NotificationStatus::Failed) {
            $this->counters->recordFailed((string) $notification->channel);
        }

        return $status;
    }

    /**
     * Итог попытки: состояние, причина и момент успешной отправки.
     */
    private function finish(
        Notification $notification,
        NotificationStatus $status,
        ?string $errorCode,
        ?Carbon $nextAttemptAt = null,
    ): void {
        $now = now()->utc();

        DB::table('notifications')
            ->where('id', $notification->getKey())
            ->update([
                'status' => $status->value,
                'last_error_code' => $errorCode,
                // Успех и бизнес-причина снимают плановую задержку: значение
                // остаётся только у инфраструктурного повтора (FR-NTF-436).
                'next_attempt_at' => $nextAttemptAt?->format('Y-m-d H:i:sP'),
                'sent_at' => $status === NotificationStatus::Sent ? $now->format('Y-m-d H:i:sP') : null,
                'updated_at' => $now->format('Y-m-d H:i:sP'),
            ]);

        $notification->refresh();
    }
}
