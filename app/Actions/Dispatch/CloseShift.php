<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\Exceptions\CourierShiftNotOpen;
use App\Domain\Dispatch\Exceptions\InvalidShiftPeriod;
use App\Domain\Dispatch\Exceptions\ShiftHasActiveAssignment;
use App\Domain\Dispatch\Shift;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift as CourierShiftModel;
use App\Models\Order\OutboxMessage;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Закрытие смены курьером (FR-DSP-104, 106).
 *
 * Строка смены блокируется на время транзакции: параллельное закрытие увидит
 * уже заполненное окончание и получит COURIER_SHIFT_NOT_OPEN. Окончание
 * ставит сервер, поэтому клиент не может подделать длительность смены.
 */
final readonly class CloseShift
{
    /**
     * Тип агрегата исходящего события.
     */
    private const AGGREGATE_TYPE = 'courier_shift';

    /**
     * Тип события окончания смены.
     */
    private const EVENT_ENDED = 'courier.shift.ended';

    /**
     * @throws ApiException смена не открыта, занята назначением или период некорректен
     */
    public function handle(CourierShiftModel $shift): CourierShiftModel
    {
        return DB::transaction(function () use ($shift): CourierShiftModel {
            $locked = CourierShiftModel::query()
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $domain = new Shift(
                (int) $locked->courier_id,
                $locked->zone_id === null ? null : (int) $locked->zone_id,
                DateTimeImmutable::createFromInterface($locked->started_at),
                $locked->ended_at === null ? null : DateTimeImmutable::createFromInterface($locked->ended_at),
            );

            $endedAt = now()->toDateTimeImmutable();

            // Активность назначения проверяется по курьеру, а не по смене:
            // назначение не привязано к конкретной смене (FR-DSP-106).
            $hasActiveAssignment = Assignment::query()
                ->where('courier_id', $locked->courier_id)
                ->active()
                ->exists();

            try {
                $domain->close($endedAt, $hasActiveAssignment);
            } catch (CourierShiftNotOpen) {
                throw ApiException::courierShiftNotOpen();
            } catch (ShiftHasActiveAssignment) {
                throw ApiException::shiftHasActiveAssignment();
            } catch (InvalidShiftPeriod) {
                throw ApiException::shiftPeriodInvalid();
            }

            // Окончание ставит домен, поле закрыто от массового присвоения.
            $locked->forceFill(['ended_at' => $endedAt])->save();

            OutboxMessage::query()->create([
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $locked->getKey(),
                'event_type' => self::EVENT_ENDED,
                // Персональных данных нет: только идентификаторы и время.
                'payload' => [
                    'shift_id' => $locked->getKey(),
                    'courier_id' => (int) $locked->courier_id,
                    'zone_id' => $locked->zone_id === null ? null : (int) $locked->zone_id,
                    'started_at' => DateTimeImmutable::createFromInterface($locked->started_at)
                        ->format(DateTimeInterface::ATOM),
                    'ended_at' => $endedAt->format(DateTimeInterface::ATOM),
                ],
            ]);

            return $locked;
        });
    }
}
