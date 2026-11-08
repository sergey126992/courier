<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationStatus;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * Выборка уведомлений, готовых к отправке (FR-NTF-272, пункт 16 плана M11).
 *
 * Готовность считается по последней строке журнала попыток и ряду backoff
 * из конфигурации: уведомление готово, если оно ещё ни разу не отправлялось,
 * если строка журнала обновлена после последней попытки (ручной повтор
 * переводит `failed` в `error`) или если с последней попытки прошла пауза
 * backoff с детерминированным разбросом.
 *
 * Плановая задержка провайдера (`next_attempt_at`, FR-NTF-436) — отдельное
 * условие: пока момент не наступил, строку не берут, даже если backoff уже
 * выдержан. Значение ставит только лимит Telegram (`retry_after`).
 *
 * Последняя попытка берётся коррелированным подзапросом по уникальному
 * индексу `(notification_id, attempt)`: это один запрос без N+1 и без
 * группировки всей таблицы попыток.
 */
final class NotificationSendQueue
{
    private const LAST_ATTEMPT = 'last_attempt';

    public function __construct(private readonly BackoffSchedule $backoff) {}

    /**
     * Пачка готовых к отправке уведомлений в порядке готовности.
     *
     * @return Collection<int, Notification>
     */
    public function due(int $limit): Collection
    {
        $query = $this->baseQuery();
        $this->applyReadiness($query);

        return $query
            ->orderByRaw('COALESCE('.self::LAST_ATTEMPT.'.created_at, notifications.created_at) ASC, notifications.id ASC')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Уведомление по идентификатору для адресной отправки (`--id`).
     */
    public function find(int $id): ?Notification
    {
        return Notification::query()->find($id);
    }

    /**
     * @return Builder<Notification>
     */
    private function baseQuery(): Builder
    {
        return Notification::query()
            ->select('notifications.*')
            ->leftJoin('notification_attempts as '.self::LAST_ATTEMPT, function (JoinClause $join): void {
                $join->on(self::LAST_ATTEMPT.'.notification_id', '=', 'notifications.id')
                    ->whereRaw(
                        self::LAST_ATTEMPT.'.attempt = (select max(attempt) from notification_attempts where notification_id = notifications.id)',
                    );
            })
            ->where(function (Builder $query): void {
                $query->whereIn('notifications.status', [
                    NotificationStatus::Created->value,
                    NotificationStatus::Error->value,
                ])->orWhere(function (Builder $stale): void {
                    // Уведомление, взятое в работу прерванным проходом поллера,
                    // снова доступно после паузы: она строго больше времени
                    // обработки пачки, поэтому ещё отправляемую запись повторно
                    // не возьмут (FR-OPS-205).
                    $stale->where('notifications.status', NotificationStatus::Queued->value)
                        ->whereRaw(
                            'notifications.updated_at <= CAST(? AS timestamptz)',
                            [now()->utc()->subSeconds($this->retryAfterSeconds())->format('Y-m-d H:i:sP')],
                        );
                });
            });
    }

    /**
     * Пауза, после которой брошенное уведомление снова доступно (FR-OPS-205).
     */
    private function retryAfterSeconds(): int
    {
        return max(1, (int) config('notifications.sender.retry_after_seconds', 1));
    }

    /**
     * @param Builder<Notification> $query
     */
    private function applyReadiness(Builder $query): void
    {
        // Плановая задержка провайдера: раньше момента строку не отправляют
        // (FR-NTF-436). Колонка nullable: у большинства строк запрета нет.
        $query->whereRaw(
            '(notifications.next_attempt_at IS NULL'
            .' OR notifications.next_attempt_at <= CAST(? AS timestamptz))',
            [now()->utc()->format('Y-m-d H:i:sP')],
        );

        // Тихие часы: строка отложена до ближайшего окончания окна
        // (FR-NTF-493, FR-NTF-494). Момент фиксируется при создании строки
        // и не пересчитывается при изменении предпочтений (FR-NTF-497).
        $query->whereRaw(
            '(notifications.deferred_until IS NULL'
            .' OR notifications.deferred_until <= CAST(? AS timestamptz))',
            [now()->utc()->format('Y-m-d H:i:sP')],
        );

        $delays = $this->backoff->delays();
        // Текущий момент передаётся из PHP: тесты управляют временем через
        // Carbon, а не часами базы.
        $bindings = [now()->utc()->format('Y-m-d H:i:sP')];
        $case = 'CASE '.self::LAST_ATTEMPT.'.attempt';

        foreach (array_values($delays) as $index => $delay) {
            $case .= ' WHEN '.($index + 1).' THEN CAST(? AS numeric)';
            $bindings[] = $delay;
        }

        $case .= ' ELSE CAST(? AS numeric) END';
        $bindings[] = $delays === [] ? 0 : $delays[count($delays) - 1];
        $bindings[] = $this->backoff->jitterRatio();

        $modulus = BackoffSchedule::JITTER_MODULUS;

        $query->whereRaw(
            '('.self::LAST_ATTEMPT.'.attempt IS NULL'
            .' OR notifications.updated_at > '.self::LAST_ATTEMPT.'.created_at'
            .' OR EXTRACT(EPOCH FROM (CAST(? AS timestamptz) - '.self::LAST_ATTEMPT.'.created_at))'
            .' >= ('.$case.') * (1 + CAST(? AS numeric) * (mod(notifications.id, '.$modulus.')::numeric / '.$modulus.')))',
            $bindings,
        );
    }
}
