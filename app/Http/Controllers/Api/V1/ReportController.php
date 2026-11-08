<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Exceptions\ReportCurrencyMismatch;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Reporting\OrderReportRequest;
use App\Http\Resources\OrderReportResource;
use App\Services\Reporting\OrderReportService;
use Illuminate\Http\JsonResponse;

/**
 * Отчёт по заказам, выручке и загрузке курьеров (ЧТЗ 8.3).
 *
 * Контроллер тонкий: доступ и формат периода проверяет OrderReportRequest,
 * правило роли — Gate `reports:read`, агрегаты собирает сервис, форму ответа
 * задаёт OrderReportResource (FR-RPT-109). Доменная ошибка валюты
 * превращается в ответ 409 только здесь, на границе HTTP (FR-RPT-154).
 */
final class ReportController extends Controller
{
    /**
     * Отчёт за период; по умолчанию — с начала текущего месяца.
     */
    public function orders(OrderReportRequest $request, OrderReportService $reports): JsonResponse
    {
        try {
            $report = $reports->build($request->period(), (string) config('reporting.currency'));
        } catch (ReportCurrencyMismatch) {
            throw ApiException::reportCurrencyMismatch();
        }

        return ApiResponse::json((new OrderReportResource($report))->resolve($request));
    }
}
