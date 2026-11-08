<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;

/**
 * Общая арифметика расчёта стоимости и прогноза времени.
 *
 * Формула: базовая ставка + ставка за километр × расстояние + надбавки.
 * Прогноз: ceil(км ÷ скорость × 60) + время на операции, в минутах.
 */
final class EstimateCalculator
{
    /**
     * Рассчитывает стоимость и прогноз доставки для заданных параметров транспорта.
     *
     * @param list<Surcharge> $surcharges применимые надбавки в порядке вывода
     *
     * @throws ParcelTooHeavyForTransport вес посылки больше предела транспорта
     * @throws ParcelOutsideDeliveryRadius расстояние больше радиуса обслуживания
     */
    public function quote(EstimateInput $input, TransportParams $params, array $surcharges = []): PriceQuote
    {
        $this->assertFitsTransport($input, $params);

        $distance = $input->distance();
        $base = $params->baseRate();
        $distanceAmount = $params->ratePerKm()->multipliedBy($distance->kilometers());

        $lines = [
            new BreakdownLine(BreakdownCode::Base, BreakdownCode::Base->label(), $base),
            new BreakdownLine(BreakdownCode::Distance, $distance->label(), $distanceAmount),
        ];
        $subtotal = $base->plus($distanceAmount);
        $total = $subtotal;

        foreach ($surcharges as $surcharge) {
            $amount = $surcharge->resolve($subtotal);
            $lines[] = new BreakdownLine($surcharge->code(), $surcharge->label(), $amount);
            $total = $total->plus($amount);
        }

        $speed = $params->speedKmH(
            $input->lightVehicleKind(),
            $params->isPeakHour($input->requestedAt()),
        );

        // Округление до 9 знаков снимает шум двоичных дробей перед округлением вверх.
        $travelMinutes = (int) ceil(round($distance->kilometers() / $speed * 60, 9));

        return new PriceQuote(
            transport: $input->transport(),
            lightVehicleKind: $input->lightVehicleKind(),
            distance: $distance,
            etaMinutes: $travelMinutes + $params->handlingMinutes(),
            total: $total,
            lines: $lines,
        );
    }

    /**
     * @throws ParcelTooHeavyForTransport вес посылки больше предела транспорта
     * @throws ParcelOutsideDeliveryRadius расстояние больше радиуса обслуживания
     */
    private function assertFitsTransport(EstimateInput $input, TransportParams $params): void
    {
        if ($input->parcel()->exceedsWeight($params->maxWeightGrams())) {
            throw ParcelTooHeavyForTransport::forParcel($input->transport(), $params->maxWeightGrams());
        }

        if ($input->distance()->exceeds($params->maxRadiusKm())) {
            throw ParcelOutsideDeliveryRadius::forDistance($input->transport(), $params->maxRadiusKm());
        }
    }
}
