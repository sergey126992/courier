<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Exceptions\BreakdownMismatch;
use App\Domain\Pricing\Exceptions\CurrencyMismatch;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceQuote;

it('создаёт результат расчёта с разбивкой', function (): void {
    $quote = new PriceQuote(
        transport: TransportType::Light,
        lightVehicleKind: LightVehicleKind::EBike,
        distance: Distance::fromKilometers(4.2),
        etaMinutes: 20,
        total: Money::rub(21200),
        lines: [
            new BreakdownLine(BreakdownCode::Base, 'Базовая ставка', Money::rub(14900)),
            new BreakdownLine(BreakdownCode::Distance, '4.2 км', Money::rub(6300)),
        ],
    );

    expect($quote->transport())->toBe(TransportType::Light)
        ->and($quote->lightVehicleKind())->toBe(LightVehicleKind::EBike)
        ->and($quote->distance()->kilometers())->toBe(4.2)
        ->and($quote->etaMinutes())->toBe(20)
        ->and($quote->total()->amount())->toBe(21200)
        ->and($quote->lines())->toHaveCount(2)
        ->and($quote->line(BreakdownCode::Base)?->amount()->amount())->toBe(14900)
        ->and($quote->line(BreakdownCode::Parking))->toBeNull();
});

it('сохраняет строку с нулевой суммой', function (): void {
    $quote = new PriceQuote(
        transport: TransportType::Car,
        lightVehicleKind: null,
        distance: Distance::fromKilometers(4.2),
        etaMinutes: 16,
        total: Money::rub(39600),
        lines: [
            new BreakdownLine(BreakdownCode::Base, 'Базовая ставка', Money::rub(24900)),
            new BreakdownLine(BreakdownCode::Distance, '4.2 км', Money::rub(14700)),
            new BreakdownLine(BreakdownCode::Surge, 'Надбавка за час пик', Money::rub(0)),
        ],
    );

    expect($quote->line(BreakdownCode::Surge)?->amount()->isZero())->toBeTrue();
});

it('отклоняет разбивку, не совпадающую с итогом', function (): void {
    expect(static fn () => new PriceQuote(
        transport: TransportType::OnFoot,
        lightVehicleKind: null,
        distance: Distance::fromKilometers(4.2),
        etaMinutes: 66,
        total: Money::rub(99900),
        lines: [
            new BreakdownLine(BreakdownCode::Base, 'Базовая ставка', Money::rub(9900)),
            new BreakdownLine(BreakdownCode::Distance, '4.2 км', Money::rub(8400)),
        ],
    ))->toThrow(BreakdownMismatch::class);
});

it('отклоняет строку разбивки в другой валюте', function (): void {
    expect(static fn () => new PriceQuote(
        transport: TransportType::OnFoot,
        lightVehicleKind: null,
        distance: Distance::fromKilometers(4.2),
        etaMinutes: 66,
        total: Money::rub(18300),
        lines: [
            new BreakdownLine(BreakdownCode::Base, 'Базовая ставка', Money::fromMinor(9900, 'USD')),
            new BreakdownLine(BreakdownCode::Distance, '4.2 км', Money::rub(8400)),
        ],
    ))->toThrow(CurrencyMismatch::class);
});
