<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventEnvelope;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\NotificationStatus;
use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\RecipientRole;
use App\Domain\Notifications\ResolvedChannels;
use App\Logging\TechnicalLogger;
use App\Models\Notification;
use App\Services\Events\EventInbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Эффект события заказа: строки журнала уведомлений (FR-NTF-241…245, FR-NTF-261…264, FR-NTF-471…476).
 *
 * Вся работа идёт внутри транзакции Inbox: отметка «сообщение обработано»
 * пишется первой, затем ключи дедупликации и строки журнала, и только после
 * COMMIT потребитель подтверждает сообщение (FR-NTF-261, FR-NTF-265).
 *
 * Каналы подбираются резолвером предпочтений для каждого получателя:
 * предпочтения читаются один раз на получателя (FR-NTF-475), а строки
 * `created` создаются только для доступных каналов. Если каналы выбраны,
 * но все недоступны заранее, создаётся одна строка `failed`
 * с `NOTIFICATION_CHANNEL_UNAVAILABLE` и обратное событие (FR-NTF-476).
 * Отключённый пользователем тип события строк и событий не порождает
 * (FR-NTF-456).
 *
 * Порядок «захват ключа → эффект» обязателен: ключ дедупликации
 * `(event_id, recipient_id, channel)` вставляется до создания уведомления,
 * поэтому повтор и параллельная обработка не дают второго эффекта
 * (FR-NTF-262…264). Проверок «есть ли уже» перед вставкой нет: конфликт
 * уникального индекса — это пропуск без ошибки.
 *
 * События, для которых в матрице нет правил (смены курьера), подтверждаются
 * и журнала не пополняют (FR-NTF-243). Строка уведомления создаётся в
 * состоянии `created`: отправку выполняет поллер (пункт 16 плана M11).
 */
final class OrderEventProcessor
{
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly NotificationPolicyResolver $resolver,
        private readonly RecipientResolver $recipients,
        private readonly EventInbox $inbox,
        private readonly NotificationEventOutbox $outbox,
        private readonly TechnicalLogger $logger,
        private readonly NotificationCounters $counters,
    ) {}

    /**
     * Обрабатывает событие и возвращает число созданных уведомлений.
     */
    public function process(string $stream, string $consumer, EventEnvelope $event): int
    {
        if (! $this->policy->notifies($event->eventType)) {
            return 0;
        }

        $recipients = $this->recipients->recipients($event);
        $hasAssignment = $event->payloadInt('courier_id') > 0;
        $created = 0;

        $this->inbox->process($stream, $consumer, $event->messageId, function () use ($event, $recipients, $hasAssignment, &$created): void {
            foreach ($recipients as $recipient) {
                // Получатель-курьер есть в списке только при наличии
                // назначения; отправителю условие назначения не мешает.
                $resolved = $this->resolver->resolveFor(
                    $recipient->userId,
                    $event->eventType,
                    $recipient->role,
                    $recipient->role === RecipientRole::Courier ? true : $hasAssignment,
                );

                if ($resolved->isDisabledByUser()) {
                    // Тип события отключён пользователем: ни строк, ни
                    // обратного события (FR-NTF-456), проверка до создания.
                    continue;
                }

                $unavailableChannel = $resolved->unavailableChannel();
                $unavailableReason = $resolved->unavailableReason();

                if ($resolved->isEmpty() && $unavailableChannel !== null && $unavailableReason !== null) {
                    // Каналы выбраны, но все недоступны: одна строка `failed`
                    // в канале верхнего приоритета и обратное событие,
                    // «тихой» потери нет (FR-NTF-476). Повтор не назначается.
                    $failed = $this->createRow($event, $recipient, $unavailableChannel, NotificationStatus::Failed, $unavailableReason);

                    if ($failed !== null) {
                        $this->outbox->enqueue($failed, NotificationStatus::Failed, 1, $unavailableReason);
                        $created++;
                    }

                    continue;
                }

                $deferredUntil = $this->deferredUntil($event, $resolved);

                foreach ($resolved->channels() as $channel) {
                    if (! NotificationTemplate::has($event->eventType, $channel)) {
                        // Канал без шаблона — бизнес-причина без повтора:
                        // остальные каналы события от этого не зависят (FR-NTF-244).
                        $this->logger->warning('Шаблон уведомления не предусмотрен.', [
                            'correlation_id' => $event->correlationId,
                            'event_id' => $event->eventId(),
                            'channel' => $channel->value,
                            'error_code' => NotificationErrorCode::TEMPLATE_NOT_FOUND,
                        ]);

                        continue;
                    }

                    $deferred = $this->defersChannel($channel) ? $deferredUntil : null;

                    $row = $this->createRow($event, $recipient, $channel, NotificationStatus::Created, null, $deferred);

                    if ($row === null) {
                        continue;
                    }

                    $created++;

                    if ($deferred !== null) {
                        // Отложенная строка видна счётчиком и записью журнала
                        // без разбора текстов (FR-OPS-413, FR-OPS-418).
                        $this->counters->recordDeferred($channel->value);

                        $this->logger->info('Отправка уведомления отложена тихими часами.', [
                            'correlation_id' => $event->correlationId,
                            'notification_id' => (int) $row->getKey(),
                            'user_id' => $recipient->userId,
                            'channel' => $channel->value,
                            'event_type' => $event->eventType->value,
                        ]);
                    }
                }
            }
        });

        return $created;
    }

    /**
     * Создаёт строку журнала после захвата ключа дедупликации.
     *
     * @return Notification|null null — ключ уже занят, второй строки нет
     */
    private function createRow(
        EventEnvelope $event,
        Recipient $recipient,
        NotificationChannel $channel,
        NotificationStatus $status,
        ?string $errorCode,
        ?CarbonImmutable $deferredUntil = null,
    ): ?Notification {
        $eventId = $event->eventId();
        $now = now()->utc()->format('Y-m-d H:i:sP');

        $captured = DB::table('notification_dedup')->insertOrIgnore([
            'event_id' => $eventId,
            'recipient_id' => $recipient->userId,
            'channel' => $channel->value,
            'notification_id' => null,
            'created_at' => $now,
        ]);

        if ($captured === 0) {
            return null;
        }

        $inserted = DB::table('notifications')->insertOrIgnore([
            'event_id' => $eventId,
            // Сквозной идентификатор события заказа возвращается в обратном
            // событии о доставке (FR-NTF-281, FR-OPS-201): поллер отправляет
            // уведомление позже, поэтому значение живёт в строке журнала.
            'correlation_id' => $event->correlationId,
            'order_id' => $event->payloadInt('order_id') > 0 ? $event->payloadInt('order_id') : null,
            // Код трекинга нужен тексту письма и push (FR-NTF-251): сервис
            // не читает базу монолита, поэтому значение хранится в строке.
            'tracking_code' => $event->payloadString('tracking_code'),
            'recipient_id' => $recipient->userId,
            'recipient_role' => $recipient->role->value,
            'channel' => $channel->value,
            'event_type' => $event->eventType->value,
            'template' => $this->template($event, $channel),
            'status' => $status->value,
            'attempts' => 0,
            'last_error_code' => $errorCode,
            'deferred_until' => $deferredUntil?->utc()->format('Y-m-d H:i:sP'),
            'sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        $notificationId = DB::table('notifications')
            ->where('event_id', $eventId)
            ->where('recipient_id', $recipient->userId)
            ->where('channel', $channel->value)
            ->value('id');

        if (! is_numeric($notificationId)) {
            return null;
        }

        DB::table('notification_dedup')
            ->where('event_id', $eventId)
            ->where('recipient_id', $recipient->userId)
            ->where('channel', $channel->value)
            ->update(['notification_id' => (int) $notificationId]);

        return Notification::query()->find((int) $notificationId);
    }

    /**
     * Момент, до которого строку нельзя отправлять из-за тихих часов.
     *
     * Критичные типы событий окно игнорируют (FR-NTF-499); при отсутствии
     * настроенного окна откладывать нечего. Момент считается один раз на
     * получателя и событие и пишется только в подходящие каналы.
     */
    private function deferredUntil(EventEnvelope $event, ResolvedChannels $resolved): ?CarbonImmutable
    {
        if ($this->isCritical($event->eventType->value)) {
            return null;
        }

        $quietHours = $resolved->quietHours();

        if ($quietHours === null) {
            return null;
        }

        return $quietHours->deferredUntil(CarbonImmutable::instance(now()));
    }

    /**
     * Критичный ли тип события: свойство типа, а не пользователя (FR-NTF-499).
     */
    private function isCritical(string $eventType): bool
    {
        return in_array($eventType, (array) config('notifications.preferences.critical_event_types', []), true);
    }

    /**
     * Откладывается ли канал окном тихих часов (FR-NTF-493, FR-NTF-496).
     *
     * Email не откладывается никогда, даже если попал в конфигурацию
     * каналов окна: письмо уходит сразу.
     */
    private function defersChannel(NotificationChannel $channel): bool
    {
        if ($channel === NotificationChannel::Email) {
            return false;
        }

        return in_array($channel->value, (array) config('notifications.preferences.quiet_hours_channels', []), true);
    }

    /**
     * Имя шаблона строки журнала: известный шаблон пары или производное имя.
     *
     * Строка `failed` пустой цепочки не отправляется, но колонка `template`
     * обязательна: имя строится по той же нотации «событие_канал».
     */
    private function template(EventEnvelope $event, NotificationChannel $channel): string
    {
        if (NotificationTemplate::has($event->eventType, $channel)) {
            return NotificationTemplate::for($event->eventType, $channel)->value;
        }

        return str_replace('.', '_', $event->eventType->value).'_'.$channel->value;
    }
}
