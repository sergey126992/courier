<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\PriceQuote;

/**
 * Общий набор проверок контракта стратегии расчёта.
 *
 * @param array<BreakdownCode> $requiredCodes
 */
function assertStrategyContract(PriceQuote $quote, TransportType $transport, array $requiredCodes): void
{
    expect($quote->transport())->toBe($transport)
        ->and($quote->total()->amount())->toBeGreaterThan(0)
        ->and($quote->etaMinutes())->toBeGreaterThan(0);

    $linesTotal = 0;

    foreach ($quote->lines() as $line) {
        $linesTotal += $line->amount()->amount();

        expect($line->amount()->currency())->toBe($quote->total()->currency())
            ->and(trim($line->label()))->not->toBe('');
    }

    expect($linesTotal)->toBe($quote->total()->amount());

    foreach ($requiredCodes as $code) {
        expect($quote->line($code))->not->toBeNull();
    }
}

it('выполняет контракт расчёта на своём транспорте', function (
    TransportType $transport,
    ?LightVehicleKind $kind,
    string $requestedAt,
    array $requiredCodes,
): void {
    $input = pricingInput($transport, $kind, requestedAt: pricingAt($requestedAt));
    $strategy = pricingStrategy($transport);

    expect($strategy->supports($input))->toBeTrue();

    assertStrategyContract($strategy->estimate($input), $transport, $requiredCodes);
})->with([
    'пеший' => [
        TransportType::OnFoot,
        null,
        '2026-03-10 15:00',
        [BreakdownCode::Base, BreakdownCode::Distance],
    ],
    'лёгкий транспорт' => [
        TransportType::Light,
        LightVehicleKind::Scooter,
        '2026-03-10 09:00',
        [BreakdownCode::Base, BreakdownCode::Distance, BreakdownCode::Surge],
    ],
    'автомобиль' => [
        TransportType::Car,
        null,
        '2026-03-10 09:00',
        [BreakdownCode::Base, BreakdownCode::Distance, BreakdownCode::Surge],
    ],
]);

it('не поддерживает чужой тип транспорта', function (
    TransportType $strategyTransport,
    TransportType $inputTransport,
    ?LightVehicleKind $kind,
): void {
    expect(pricingStrategy($strategyTransport)->supports(pricingInput($inputTransport, $kind)))->toBeFalse();
})->with([
    'пеший против лёгкого' => [TransportType::OnFoot, TransportType::Light, LightVehicleKind::Bicycle],
    'лёгкий против авто' => [TransportType::Light, TransportType::Car, null],
    'авто против пешего' => [TransportType::Car, TransportType::OnFoot, null],
]);
