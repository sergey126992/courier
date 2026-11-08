<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationStatus;
use App\Logging\TechnicalLogger;
use App\Models\Notification;
use Throwable;

/**
 * Поллер отправки: пачка, попытки, изоляция сбоев (FR-NTF-272…277).
 *
 * Поллер обрабатывает пачку построчно: сбой одного уведомления не отменяет
 * отправку остальных. Классы ошибок разбирает `NotificationSender`, здесь
 * только подсчёт итогов и защита прохода от непредвиденного исключения
 * (например, недоступности базы на отдельной строке).
 */
final class NotificationPoller
{
    public function __construct(
        private readonly NotificationSendQueue $queue,
        private readonly NotificationSender $sender,
        private readonly TechnicalLogger $logger,
        private readonly NotificationCounters $counters,
    ) {}

    /**
     * Один проход по готовым к отправке уведомлениям.
     *
     * После пачки счётчики отправленных, необработанных и повторённых
     * уведомлений по каналам пишутся в журнал (FR-OPS-208): дашбордов
     * и общего сборщика метрик на этапе M11 нет.
     */
    public function run(int $limit): SenderResult
    {
        $result = new SenderResult;

        foreach ($this->queue->due($limit) as $notification) {
            $this->process($notification, $result);
        }

        if ($result->processed > 0) {
            foreach ($this->counters->byChannel() as $counter) {
                $this->logger->info('Счётчик уведомлений по каналу.', $counter);
            }

            // Событийные счётчики M12: замещения, отложенные тихими часами
            // и ошибки Telegram по коду (FR-OPS-413). Форма `byChannel()`
            // не меняется, `/metrics` появится на M13.
            foreach ($this->counters->events() as $counter) {
                $this->logger->info('Счётчик событий уведомлений.', $counter);
            }
        }

        // Счётчики и гистограмма M13 уходят в общий кэш: `/metrics` читает
        // готовый документ и не пересчитывает журнал (FR-OPS-508).
        $this->counters->flushToCache();

        return $result;
    }

    /**
     * Адресная отправка одного уведомления (`--id`).
     *
     * Готовность к повтору не проверяется: команда вызвана осознанно.
     */
    public function sendOne(int $id): SenderResult
    {
        $result = new SenderResult;
        $notification = $this->queue->find($id);

        if ($notification === null) {
            $result->missing = true;

            return $result;
        }

        $this->process($notification, $result);

        // Адресная отправка тоже обновляет общий снимок метрик (FR-OPS-508).
        $this->counters->flushToCache();

        return $result;
    }

    private function process(Notification $notification, SenderResult $result): void
    {
        $result->processed++;

        // Контекст записи журнала: сквозной correlation_id события и поля
        // уведомления. Адреса и текстов в контексте нет (FR-OPS-202).
        $fields = [
            'correlation_id' => $notification->correlation_id,
            'notification_id' => (int) $notification->getKey(),
            'order_id' => $notification->order_id,
            'recipient_id' => (int) $notification->recipient_id,
            'channel' => (string) $notification->channel,
        ];

        try {
            $status = $this->logger->run($fields, fn (): NotificationStatus => $this->sender->send($notification));
        } catch (Throwable) {
            // Отравленное уведомление не блокирует пачку: причина в логе
            // без персональных данных, остальные строки обрабатываются.
            $result->failed++;
            $this->logger->error('Поллер не смог обработать уведомление.', $fields + [
                'status' => NotificationStatus::Failed->value,
                'attempt' => max(1, (int) $notification->attempts),
            ]);

            return;
        }

        match ($status) {
            NotificationStatus::Sent => $result->sent++,
            NotificationStatus::Failed => $result->failed++,
            default => $result->error++,
        };
    }
}
