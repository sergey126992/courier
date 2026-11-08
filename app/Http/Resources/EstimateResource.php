<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\PriceQuote;
use Illuminate\Http\Request;

/**
 * Ответ предварительного расчёта: цена, прогноз и разбивка по строкам.
 *
 * Контракт ЧТЗ задаёт плоскую структуру без обёртки data, поэтому она отключена.
 * Суммы отдаются в минорных единицах, валюта — отдельным полем.
 */
final class EstimateResource extends ApiResource
{
    /**
     * Обёртка ответа отключена: поля расчёта лежат в корне JSON.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PriceQuote $quote */
        $quote = $this->resource;

        return [
            'transport' => $quote->transport()->value,
            'light_vehicle_kind' => $quote->lightVehicleKind()?->value,
            'distance_km' => $quote->distance()->kilometers(),
            'eta_minutes' => $quote->etaMinutes(),
            'price' => [
                'amount' => $quote->total()->amount(),
                'currency' => $quote->total()->currency(),
            ],
            // Нулевые строки сохраняются: клиент видит структуру цены целиком.
            'breakdown' => array_map(
                static fn (BreakdownLine $line): array => [
                    'code' => $line->code()->value,
                    'label' => $line->label(),
                    'amount' => $line->amount()->amount(),
                ],
                $quote->lines(),
            ),
        ];
    }
}
