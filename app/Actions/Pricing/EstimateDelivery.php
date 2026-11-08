<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Geography\Address;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Exceptions\AddressOutsideServiceArea;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Geography\ZoneResolver;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;
use App\Domain\Pricing\Exceptions\UnsupportedTransport;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\StrategyDeliveryEstimator;
use App\Http\Problem\ApiException;
use App\Services\Pricing\EstimateInputFactory;

/**
 * Предварительный расчёт стоимости и прогноза доставки.
 *
 * Операция ничего не сохраняет: результат зависит только от входных данных
 * и параметров расчёта. Расстояние не приходит от клиента: зона определяется
 * по адресу забора, расстояние считает провайдер. Доменные ограничения
 * переводятся в коды ошибок API здесь, поэтому контроллер остаётся тонким.
 */
final readonly class EstimateDelivery
{
    public function __construct(
        private StrategyDeliveryEstimator $estimator,
        private EstimateInputFactory $inputs,
        private ZoneResolver $zoneResolver,
        private DistanceProvider $distanceProvider,
    ) {}

    /**
     * @param array<string, mixed> $data проверенные данные запроса
     *
     * @throws ApiException доменное ограничение расчёта или отсутствие стратегии
     */
    public function handle(array $data): PriceQuote
    {
        $pickup = $this->inputs->address($data, 'pickup');
        $dropoff = $this->inputs->address($data, 'dropoff');

        $input = $this->inputs->build($data, $this->distance($pickup, $dropoff));

        try {
            return $this->estimator->estimate($input);
        } catch (ParcelTooHeavyForTransport $exception) {
            throw ApiException::parcelTooHeavyForTransport($exception->maxWeightGrams(), $exception->transport());
        } catch (ParcelOutsideDeliveryRadius $exception) {
            throw ApiException::parcelOutsideDeliveryRadius($exception->maxRadiusKm(), $exception->transport());
        } catch (UnsupportedTransport $exception) {
            throw ApiException::unsupportedTransport($exception->transport());
        }
    }

    /**
     * Расстояние маршрута: зона забора проверяется, дальше считает провайдер.
     *
     * Зона выбирается по адресу забора (FR-GEO-115): для расчёта нужна именно она.
     * Адрес доставки может быть вне зон — расстояние всё равно определено,
     * поэтому проверяется только забор.
     *
     * @throws ApiException адрес забора вне зон или провайдер недоступен
     */
    private function distance(Address $pickup, Address $dropoff): Distance
    {
        try {
            $this->zoneResolver->resolve($pickup->coordinates());
        } catch (AddressOutsideServiceArea) {
            throw ApiException::addressOutsideServiceArea('pickup');
        }

        try {
            return $this->distanceProvider->between($pickup->coordinates(), $dropoff->coordinates());
        } catch (DistanceProviderUnavailable) {
            // Причина сбоя клиенту не раскрывается: он получает код и общий статус.
            throw ApiException::distanceProviderUnavailable();
        }
    }
}
