<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\StrategyDeliveryEstimator;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Расчёт проверяет зону по адресу забора до выбора стратегии.
beforeEach(function (): void {
    estimateServiceZone();
});

/**
 * Стратегия-заглушка: поддерживает любой транспорт и возвращает одну строку.
 */
function estimateStubStrategy(): DeliveryEstimateStrategy
{
    return new class implements DeliveryEstimateStrategy
    {
        public function supports(EstimateInput $input): bool
        {
            return true;
        }

        public function estimate(EstimateInput $input): PriceQuote
        {
            return new PriceQuote(
                transport: $input->transport(),
                lightVehicleKind: $input->lightVehicleKind(),
                distance: $input->distance(),
                etaMinutes: 1,
                total: Money::rub(100),
                lines: [new BreakdownLine(BreakdownCode::Base, 'Заглушка', Money::rub(100))],
            );
        }
    };
}

it('собирает компонент выбора стратегий из контейнера', function (TransportType $transport, ?LightVehicleKind $kind): void {
    $estimator = app(StrategyDeliveryEstimator::class);

    $quote = $estimator->estimate(pricingInput($transport, $kind));

    expect($quote->transport())->toBe($transport)
        ->and($quote->etaMinutes())->toBeGreaterThan(0)
        // Компонент выбора — singleton: набор стратегий собирается один раз.
        ->and(app(StrategyDeliveryEstimator::class))->toBe($estimator);
})->with([
    'пеший' => [TransportType::OnFoot, null],
    'лёгкий транспорт' => [TransportType::Light, LightVehicleKind::EBike],
    'автомобиль' => [TransportType::Car, null],
]);

it('работает с подменённым набором стратегий без правки вызывающего кода', function (): void {
    app()->instance(
        StrategyDeliveryEstimator::class,
        new StrategyDeliveryEstimator([estimateStubStrategy()]),
    );

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/estimates', estimatePayload(['transport' => 'car']))
        ->assertOk()
        ->assertJsonPath('transport', 'car')
        ->assertJsonPath('eta_minutes', 1)
        ->assertJsonPath('price.amount', 100)
        ->assertJsonPath('breakdown.0.label', 'Заглушка');
});

it('возвращает понятную ошибку, если набор стратегий не поддержал транспорт', function (): void {
    // Набор без пешей стратегии: вызывающий код не меняется, ошибка приходит кодом.
    app()->instance(
        StrategyDeliveryEstimator::class,
        new StrategyDeliveryEstimator([pricingStrategy(TransportType::Car)]),
    );

    Sanctum::actingAs(User::factory()->sender()->create());

    $response = $this->postJson('/api/v1/estimates', estimatePayload(['transport' => 'on_foot']));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'UNSUPPORTED_TRANSPORT')
        ->assertJsonPath('details.0.field', 'transport');

    expect((string) $response->json('details.0.message'))->toContain('Пешком');
});
