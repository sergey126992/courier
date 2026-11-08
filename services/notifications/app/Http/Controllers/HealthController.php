<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Logging\TechnicalLogger;
use App\Support\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * Состояние сервиса для внешнего мониторинга (FR-NTF-214, FR-OPS-203).
 *
 * Маршрут открыт без токена и отдаёт только факты: 200 — сервис в норме,
 * 503 — сбой зависимости или превышение порога. Персональных данных,
 * токенов, адресов и идентификаторов пользователей в ответе нет (FR-OPS-210).
 */
final class HealthController extends Controller
{
    public function __construct(private readonly TechnicalLogger $logger) {}

    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $report = $checker->check();

        if (! $report->isHealthy()) {
            // Деградация видна и в журнале, а не только в ответе (FR-OPS-203):
            // лаг и число необработанных читаются без разбора текста.
            $this->logger->warning('Состояние сервиса деградировано.', [
                'status' => $report->status,
                'lag_seconds' => $report->lagSeconds,
                'pending' => $report->pending,
            ]);
        }

        return response()->json($report->toArray(), $report->isHealthy() ? 200 : 503);
    }
}
