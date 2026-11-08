<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Parcel;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\Strategies\CarEstimateStrategy;
use App\Domain\Pricing\Strategies\LightTransportEstimateStrategy;
use App\Domain\Pricing\Strategies\OnFootEstimateStrategy;
use App\Domain\Pricing\TransportParams;
use App\Services\Pricing\PricingConfig;
use Carbon\CarbonImmutable;

/**
 * Конфигурация расчёта, прочитанная из приложения.
 */
function pricingConfig(): PricingConfig
{
    return app(PricingConfig::class);
}

/**
 * Параметры расчёта для типа транспорта из конфигурации приложения.
 */
function pricingParams(TransportType $transport): TransportParams
{
    return pricingConfig()->transportParams($transport);
}

/**
 * Стратегия расчёта для типа транспорта с параметрами из конфигурации.
 */
function pricingStrategy(TransportType $transport): DeliveryEstimateStrategy
{
    $calculator = new EstimateCalculator;
    $params = pricingParams($transport);

    return match ($transport) {
        TransportType::OnFoot => new OnFootEstimateStrategy($calculator, $params),
        TransportType::Light => new LightTransportEstimateStrategy($calculator, $params),
        TransportType::Car => new CarEstimateStrategy($calculator, $params),
    };
}

/**
 * Момент времени в часовом поясе приложения.
 */
function pricingAt(string $time = '2026-03-10 15:00'): DateTimeImmutable
{
    return CarbonImmutable::parse($time, (string) config('app.timezone'))->toDateTimeImmutable();
}

/**
 * Входные данные расчёта с разумными значениями по умолчанию.
 */
function pricingInput(
    TransportType $transport,
    ?LightVehicleKind $kind = null,
    float $distanceKm = 1.5,
    int $weightGrams = 1500,
    ?DateTimeImmutable $requestedAt = null,
    bool $isPaidParking = false,
): EstimateInput {
    return new EstimateInput(
        transport: $transport,
        lightVehicleKind: $kind,
        distance: Distance::fromKilometers($distanceKm),
        parcel: new Parcel($weightGrams, 30, 20, 10),
        requestedAt: $requestedAt ?? pricingAt(),
        isPaidParking: $isPaidParking,
    );
}

/**
 * Тело запроса предварительного расчёта с разумными значениями по умолчанию.
 *
 * Расстояние клиент не передаёт: сервер считает его по адресам. По умолчанию
 * маршрут длиной 1.8 км — он укладывается в радиус пешего транспорта.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function estimatePayload(array $overrides = []): array
{
    [$pickup, $dropoff] = estimateRoute(1.8);

    return array_merge([
        'transport' => TransportType::OnFoot->value,
        'pickup' => $pickup,
        'dropoff' => $dropoff,
        // Посылка — вложенный объект: та же форма, что при создании заказа.
        'parcel' => [
            'weight_grams' => 1500,
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ],
        'requested_at' => '2026-03-10T15:00:00+03:00',
    ], $overrides);
}

/**
 * Тело запроса расчёта по маршруту заданной длины.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function estimatePayloadForRoute(float $distanceKm, array $overrides = []): array
{
    [$pickup, $dropoff] = estimateRoute($distanceKm);

    return estimatePayload(array_merge(['pickup' => $pickup, 'dropoff' => $dropoff], $overrides));
}

/**
 * Адрес в формате запроса расчёта и создания заказа.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function estimateAddress(float $latitude, float $longitude, array $overrides = []): array
{
    return array_merge([
        'district' => 'Тверской',
        'street' => 'Тверская',
        'house' => '7',
        'latitude' => $latitude,
        'longitude' => $longitude,
    ], $overrides);
}

/**
 * Коэффициент удлинения маршрута по умолчанию из config/geography.php.
 *
 * Хелперы тестовых данных вычисляются до загрузки приложения (наборы данных
 * Pest), где config() недоступен, поэтому значение продублировано константой.
 * Совпадение с конфигурацией проверяет тест расчёта по адресам.
 */
const ESTIMATE_ROUTE_ELONGATION = 1.3;

/**
 * Пара адресов, расстояние между которыми равно заданному с точностью до сотых.
 *
 * Точки стоят на одном меридиане от опорной точки: длина маршрута зависит только
 * от разницы широт, а коэффициент удлинения берётся из конфигурации. Поэтому
 * ожидаемое расстояние в ответе совпадает с переданным числом.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function estimateRoute(float $distanceKm): array
{
    // Километров в одном градусе широты по среднему радиусу Земли (6371.0088 км).
    $kilometersPerDegree = 6371.0088 * M_PI / 180;
    $latitudeDelta = $distanceKm / ($kilometersPerDegree * ESTIMATE_ROUTE_ELONGATION);

    return [
        estimateAddress(55.755826, 37.6173),
        estimateAddress(round(55.755826 + $latitudeDelta, 6), 37.6173),
    ];
}

/**
 * Строка разбивки результата расчёта, если она есть.
 */
function pricingLine(PriceQuote $quote, BreakdownCode $code): ?BreakdownLine
{
    return $quote->line($code);
}

/**
 * Сумма строки разбивки; отсутствие строки считается дефектом теста.
 */
function pricingLineAmount(PriceQuote $quote, BreakdownCode $code): int
{
    $line = $quote->line($code);

    if ($line === null) {
        throw new RuntimeException('Строка разбивки «'.$code->value.'» отсутствует в результате.');
    }

    return $line->amount()->amount();
}
