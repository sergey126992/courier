<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;
use App\Domain\Pricing\Exceptions\UnsupportedTransport;
use App\Domain\Pricing\PriceQuote;

/**
 * Контракт расчёта стоимости и прогноза доставки для одного типа транспорта.
 *
 * Стратегия не знает, кто её выбрал, и не обращается к контейнеру, базе данных,
 * HTTP или конфигурации: её параметры передаются при создании.
 */
interface DeliveryEstimateStrategy
{
    /**
     * Поддерживает ли стратегия входные данные.
     *
     * Проверка не меняет состояние и не выполняет расчёт.
     */
    public function supports(EstimateInput $input): bool;

    /**
     * Рассчитывает стоимость и прогноз доставки.
     *
     * @throws ParcelTooHeavyForTransport посылка тяжелее предела транспорта
     * @throws ParcelOutsideDeliveryRadius адрес вне радиуса обслуживания
     * @throws UnsupportedTransport транспорт не поддерживается
     */
    public function estimate(EstimateInput $input): PriceQuote;
}
