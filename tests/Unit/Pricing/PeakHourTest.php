<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\EstimateInput;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Входные данные с моментом заявки, зафиксированным подменой текущего времени.
 */
function pricingInputAt(string $time, TransportType $transport, ?LightVehicleKind $kind = null): EstimateInput
{
    Carbon::setTestNow(Carbon::parse($time, (string) config('app.timezone')));

    return pricingInput($transport, $kind, requestedAt: Carbon::now()->toDateTimeImmutable());
}

it('начисляет надбавку за час пик в 09:00 и не начисляет в 15:00', function (): void {
    $strategy = pricingStrategy(TransportType::Car);

    $peak = $strategy->estimate(pricingInputAt('2026-03-10 09:00', TransportType::Car));
    $day = $strategy->estimate(pricingInputAt('2026-03-10 15:00', TransportType::Car));

    expect(pricingLineAmount($peak, BreakdownCode::Surge))->toBeGreaterThan(0)
        ->and(pricingLineAmount($day, BreakdownCode::Surge))->toBe(0)
        ->and($peak->etaMinutes())->toBeGreaterThan($day->etaMinutes());
});

it('включает границы интервалов часа пик', function (string $time, bool $isPeak): void {
    $quote = pricingStrategy(TransportType::Car)->estimate(pricingInputAt($time, TransportType::Car));

    expect(pricingLineAmount($quote, BreakdownCode::Surge) > 0)->toBe($isPeak);
})->with([
    'утро, начало интервала' => ['2026-03-10 07:00', true],
    'утро, конец интервала' => ['2026-03-10 10:00', true],
    'после утреннего интервала' => ['2026-03-10 10:01', false],
    'вечер, конец интервала' => ['2026-03-10 20:00', true],
    'после вечернего интервала' => ['2026-03-10 20:01', false],
]);

it('начисляет надбавку за час пик только самокатам лёгкого транспорта', function (): void {
    $strategy = pricingStrategy(TransportType::Light);

    $scooter = $strategy->estimate(pricingInputAt('2026-03-10 09:00', TransportType::Light, LightVehicleKind::Scooter));
    $bicycle = $strategy->estimate(pricingInputAt('2026-03-10 09:00', TransportType::Light, LightVehicleKind::Bicycle));

    expect(pricingLineAmount($scooter, BreakdownCode::Surge))->toBeGreaterThan(0)
        ->and(pricingLineAmount($bicycle, BreakdownCode::Surge))->toBe(0);
});

it('не начисляет надбавку за час пик пешему курьеру', function (): void {
    $quote = pricingStrategy(TransportType::OnFoot)->estimate(pricingInputAt('2026-03-10 09:00', TransportType::OnFoot));

    expect(pricingLine($quote, BreakdownCode::Surge))->toBeNull()
        ->and($quote->lines())->toHaveCount(2);
});
