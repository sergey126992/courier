<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Pricing\EstimateDelivery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\EstimateDeliveryRequest;
use App\Http\Resources\EstimateResource;

/**
 * Предварительный расчёт стоимости и прогноза доставки.
 *
 * Контроллер только передаёт проверенные данные операции: доменные ограничения
 * и права проверяются до него.
 */
final class EstimateController extends Controller
{
    public function __invoke(EstimateDeliveryRequest $request, EstimateDelivery $estimateDelivery): EstimateResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return EstimateResource::make($estimateDelivery->handle($data));
    }
}
