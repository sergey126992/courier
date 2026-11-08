<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\Exceptions\DlqEntryNotFound;
use App\Domain\Notifications\Exceptions\DlqEntryNotReplayable;
use App\Domain\Notifications\NotificationStatus;
use App\Models\DlqEntry;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Повтор записи DLQ и очистка по сроку хранения (FR-NTF-506…508).
 *
 * Повтор идемпотентен: возвращает **ту же** строку журнала в `error` и ставит
 * её в очередь поллера. Второе уведомление и вторая запись DLQ не создаются,
 * запись помечается `replayed` с увеличением `replay_count` и моментом
 * последнего повтора (FR-NTF-507). Повторять можно только открытую запись.
 *
 * Плановая задержка берётся из ряда backoff конфигурации: строка станет
 * готова к отправке после первой паузы, а поллер возьмёт её обычным порядком.
 * Нового транспорта и второй ветки отправки нет (план M13, S2).
 *
 * Очистка — осознанное действие по сроку `dlq.retention_days`: триггеров нет,
 * команда и расписание появятся в S3. Запись при повторе не удаляется
 * (FR-NTF-508).
 */
final class DlqService
{
    public function __construct(private readonly BackoffSchedule $backoff) {}

    /**
     * Повтор открытой записи DLQ: та же строка журнала снова в `error`.
     *
     * @throws DlqEntryNotFound записи или строки журнала нет
     * @throws DlqEntryNotReplayable запись уже в повторе или разобрана
     */
    public function replay(int $entryId): DlqEntry
    {
        return DB::transaction(function () use ($entryId): DlqEntry {
            $entry = DlqEntry::query()->lockForUpdate()->find($entryId);

            if ($entry === null) {
                throw DlqEntryNotFound::make($entryId);
            }

            if (! $entry->state->canReplay()) {
                throw DlqEntryNotReplayable::make($entry->state);
            }

            $notification = Notification::query()->lockForUpdate()->find((int) $entry->notification_id);

            if ($notification === null) {
                // Журнал очищен по своему сроку: запись DLQ осталась, повтора нет.
                throw DlqEntryNotFound::make($entryId);
            }

            $now = now()->utc();
            $notificationId = (int) $notification->getKey();
            $nextAttemptAt = $now->copy()->addSeconds($this->backoff->delayFor(1, $notificationId));

            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => NotificationStatus::Error->value,
                    'next_attempt_at' => $nextAttemptAt->format('Y-m-d H:i:sP'),
                    'sent_at' => null,
                    'updated_at' => $now->format('Y-m-d H:i:sP'),
                ]);

            $entry->forceFill([
                'state' => DlqState::Replayed,
                'replay_count' => $entry->replay_count + 1,
                'last_replayed_at' => $now,
            ])->save();

            return $entry->refresh();
        });
    }

    /**
     * Удаляет записи старше срока хранения DLQ; журнал уведомлений не трогает.
     *
     * @return int число удалённых записей
     */
    public function purgeExpired(?int $days = null): int
    {
        $retention = $days ?? (int) config('notifications.dlq.retention_days');

        return DlqEntry::query()
            ->where('failed_at', '<', now()->utc()->subDays(max(1, $retention)))
            ->delete();
    }
}
