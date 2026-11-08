<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Geography\Coordinates;
use App\Domain\Tracking\Exceptions\TrackingPointInFuture;
use App\Domain\Tracking\Exceptions\TrackingPointOutOfSequence;
use App\Domain\Tracking\TrackingPointDraft;
use App\Domain\Tracking\TrackingPointSource;
use App\Domain\Tracking\TrackingSequence;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\CourierLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Приём геоточки по назначению (FR-TRK-101…112).
 *
 * Порядок: блокировка назначения → проверка рабочего статуса → проверка
 * времени доменом → добавление точки → обновление последних позиций.
 * Последняя позиция назначения и курьера обновляется вставкой с заменой по
 * уникальному ключу, поэтому строк всегда по одной и дубликатов не бывает.
 *
 * События не пишутся: приём точки — внутренняя запись трекинга (FR-TRK-107).
 */
final readonly class RecordTrackingPoint
{
    /**
     * Статусы назначения, в которых курьер может отправлять точки (FR-TRK-101).
     *
     * @var list<AssignmentStatus>
     */
    private const ALLOWED_STATUSES = [AssignmentStatus::Accepted, AssignmentStatus::PickedUp];

    /**
     * @throws ApiException назначение неактивно, время точки в будущем или не по порядку
     */
    public function handle(
        Assignment $assignment,
        User $courier,
        Coordinates $point,
        ?DateTimeImmutable $recordedAt,
    ): TrackingPoint {
        // Допуск живёт в конфигурации, а домен получает его значением.
        $toleranceMinutes = (int) config('tracking.point_future_tolerance_minutes');

        return DB::transaction(function () use ($assignment, $courier, $point, $recordedAt, $toleranceMinutes): TrackingPoint {
            $locked = Assignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, self::ALLOWED_STATUSES, true)) {
                throw ApiException::assignmentNotActive();
            }

            $draft = new TrackingPointDraft(
                $point,
                $recordedAt ?? now()->toDateTimeImmutable(),
                TrackingPointSource::Courier,
            );

            $this->assertTimeAllowed($locked, $draft, $toleranceMinutes);

            $created = TrackingPoint::query()->create([
                'assignment_id' => $locked->getKey(),
                'latitude' => $draft->coordinates->latitude(),
                'longitude' => $draft->coordinates->longitude(),
                'recorded_at' => $draft->recordedAt,
                'source' => $draft->source,
            ]);

            $this->storeLastPositions(
                (int) $locked->getKey(),
                (int) $courier->getKey(),
                $draft->coordinates,
                $created->recorded_at,
            );

            return $created;
        });
    }

    /**
     * Проверка порядка времени по времени последней точки назначения.
     *
     * @throws ApiException время в будущем сверх допуска или не больше предыдущей точки
     */
    private function assertTimeAllowed(Assignment $assignment, TrackingPointDraft $draft, int $toleranceMinutes): void
    {
        $previous = TrackingPoint::query()
            ->where('assignment_id', $assignment->getKey())
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        try {
            TrackingSequence::assertAccepts(
                $draft->recordedAt,
                $previous?->recorded_at->toDateTimeImmutable(),
                now()->toDateTimeImmutable(),
                $toleranceMinutes * 60,
            );
        } catch (TrackingPointInFuture) {
            throw ApiException::trackingPointInFuture($toleranceMinutes);
        } catch (TrackingPointOutOfSequence) {
            throw ApiException::trackingPointOutOfSequence();
        }
    }

    /**
     * Обновляет последнюю позицию назначения и курьера без роста числа строк.
     */
    private function storeLastPositions(
        int $assignmentId,
        int $courierId,
        Coordinates $coordinates,
        Carbon $recordedAt,
    ): void {
        $timestamp = now();
        $values = $coordinates->toArray();

        $position = [
            'latitude' => $values['latitude'],
            'longitude' => $values['longitude'],
            'recorded_at' => $recordedAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        AssignmentLastPosition::query()->upsert(
            [['assignment_id' => $assignmentId] + $position],
            ['assignment_id'],
            ['latitude', 'longitude', 'recorded_at', 'updated_at'],
        );

        CourierLastPosition::query()->upsert(
            [['courier_id' => $courierId] + $position],
            ['courier_id'],
            ['latitude', 'longitude', 'recorded_at', 'updated_at'],
        );
    }
}
