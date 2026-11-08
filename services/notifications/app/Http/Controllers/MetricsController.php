<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemResponse;
use App\Metrics\NotificationMetrics;
use App\Metrics\PrometheusTextExporter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Метрики сервиса в формате Prometheus text 0.0.4 (FR-OPS-501…508).
 *
 * Маршрут закрыт сервисным токеном: сравнение постоянным по времени
 * выполняет middleware `service-token`, ошибка авторизации — `401`
 * в `application/problem+json` без деталей окружения (FR-OPS-509).
 *
 * Выключенный ключом `metrics.enabled` экспорт ведёт себя как
 * неопубликованный маршрут: `404` в `application/problem+json`.
 * Недоступный источник метрик не роняет сервис — отдаётся то, что есть,
 * а деградация фиксируется в журнале (FR-OPS-512).
 */
final class MetricsController extends Controller
{
    public function __invoke(NotificationMetrics $metrics, PrometheusTextExporter $exporter): Response
    {
        if (! (bool) config('notifications.metrics.enabled')) {
            return ProblemResponse::make(ErrorCode::NotFound);
        }

        return response(
            $exporter->export($metrics->snapshot()),
            200,
            ['Content-Type' => PrometheusTextExporter::CONTENT_TYPE],
        );
    }
}
