<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;

it('содержит значения по умолчанию из требований', function (): void {
    expect(config('pricing.transport.on_foot.max_weight_grams'))->toBe(3000)
        ->and(config('pricing.transport.on_foot.max_radius_km'))->toBe(2.0)
        ->and(config('pricing.transport.on_foot.base_rate_minor'))->toBe(9900)
        ->and(config('pricing.transport.on_foot.rate_per_km_minor'))->toBe(2000)
        ->and(config('pricing.transport.on_foot.speed_kmh'))->toBe(4.5)
        ->and(config('pricing.transport.on_foot.handling_minutes'))->toBe(10)
        ->and(config('pricing.transport.light.max_weight_grams'))->toBe(15000)
        ->and(config('pricing.transport.light.max_radius_km'))->toBe(7.0)
        ->and(config('pricing.transport.light.base_rate_minor'))->toBe(14900)
        ->and(config('pricing.transport.light.rate_per_km_minor'))->toBe(2500)
        ->and(config('pricing.transport.light.peak_surcharge_percent'))->toBe(10.0)
        ->and(config('pricing.transport.light.handling_minutes'))->toBe(7)
        ->and(config('pricing.transport.light.speeds_kmh'))->toBe([
            'bicycle' => 15.0,
            'scooter' => 12.0,
            'e_scooter' => 18.0,
            'e_bike' => 20.0,
        ])
        ->and(config('pricing.transport.car.max_weight_grams'))->toBe(100000)
        ->and(config('pricing.transport.car.max_radius_km'))->toBe(20.0)
        ->and(config('pricing.transport.car.base_rate_minor'))->toBe(24900)
        ->and(config('pricing.transport.car.rate_per_km_minor'))->toBe(3500)
        ->and(config('pricing.transport.car.speed_kmh'))->toBe(25.0)
        ->and(config('pricing.transport.car.peak_speed_kmh'))->toBe(18.0)
        ->and(config('pricing.transport.car.peak_surcharge_percent'))->toBe(15.0)
        ->and(config('pricing.transport.car.parking_fee_minor'))->toBe(5000)
        ->and(config('pricing.transport.car.handling_minutes'))->toBe(5);
});

it('задаёт интервалы часа пик и часовой пояс приложения', function (): void {
    expect(config('pricing.peak_hours.intervals'))->toBe([
        ['from' => '07:00', 'to' => '10:00'],
        ['from' => '17:00', 'to' => '20:00'],
    ])->and(pricingConfig()->peakHours()->timezone())->toBe(config('app.timezone'));
});

it('меняет результат при изменении ставки в конфигурации', function (): void {
    $before = pricingStrategy(TransportType::OnFoot)->estimate(pricingInput(TransportType::OnFoot));
    $defaultBase = pricingLineAmount($before, BreakdownCode::Base);

    config(['pricing.transport.on_foot.base_rate_minor' => 50000]);

    $after = pricingStrategy(TransportType::OnFoot)->estimate(pricingInput(TransportType::OnFoot));

    expect(pricingLineAmount($after, BreakdownCode::Base))->toBe(50000)
        ->and($after->total()->amount())->toBe($before->total()->amount() - $defaultBase + 50000);
});

it('меняет прогноз при изменении скорости в конфигурации', function (): void {
    $before = pricingStrategy(TransportType::OnFoot)->estimate(pricingInput(TransportType::OnFoot));

    config(['pricing.transport.on_foot.speed_kmh' => 9.0]);

    $after = pricingStrategy(TransportType::OnFoot)->estimate(pricingInput(TransportType::OnFoot));

    expect($after->etaMinutes())->toBeLessThan($before->etaMinutes());
});

it('меняет надбавку при изменении процента в конфигурации', function (): void {
    $input = pricingInput(TransportType::Car, requestedAt: pricingAt('2026-03-10 09:00'));
    $before = pricingStrategy(TransportType::Car)->estimate($input);

    config(['pricing.transport.car.peak_surcharge_percent' => 30.0]);

    $after = pricingStrategy(TransportType::Car)->estimate($input);

    expect(pricingLineAmount($after, BreakdownCode::Surge))
        ->toBeGreaterThan(pricingLineAmount($before, BreakdownCode::Surge));
});
