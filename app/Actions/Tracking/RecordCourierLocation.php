<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Domain\Geography\Coordinates;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\CourierShift;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Heartbeat позиции курьера (FR-TRK-112…114).
 *
 * Heartbeat допустим при открытой смене независимо от активного назначения:
 * обновляется только последняя позиция курьера, история геоточек назначения
 * не меняется. Без смены — код COURIER_SHIFT_NOT_OPEN.
 */
final readonly class RecordCourierLocation
{
    /**
     * @throws ApiException у курьера нет открытой смены
     */
    public function handle(User $courier, Coordinates $point, ?DateTimeImmutable $at): void
    {
        $courierId = (int) $courier->getKey();
        $recordedAt = $at ?? now()->toDateTimeImmutable();

        DB::transaction(function () use ($courierId, $point, $recordedAt): void {
            $hasOpenShift = CourierShift::query()
                ->where('courier_id', $courierId)
                ->open()
                ->exists();

            if (! $hasOpenShift) {
                throw ApiException::courierShiftNotOpen();
            }

            $timestamp = now();
            $values = $point->toArray();

            // Одна строка на курьера: upsert по уникальному ключу без дублей.
            CourierLastPosition::query()->upsert(
                [[
                    'courier_id' => $courierId,
                    'latitude' => $values['latitude'],
                    'longitude' => $values['longitude'],
                    'recorded_at' => $recordedAt,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
                ['courier_id'],
                ['latitude', 'longitude', 'recorded_at', 'updated_at'],
            );
        });
    }
}
