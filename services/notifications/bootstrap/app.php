<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Middleware\AuthenticateServiceToken;
use App\Http\Middleware\CorrelationId;
use App\Http\Problem\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Запуск сервиса оповещений
|--------------------------------------------------------------------------
|
| Отдельное приложение со своим HTTP API (/api/v1), консольными командами
| процессов и служебной проверкой /up. Пользовательского интерфейса нет:
| админ-поверхности живут в монолите и обращаются к API сервиса (FR-NTF-211).
|
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Проверка состояния сервиса живёт вне групп middleware: ответ не
        // несёт сессионных cookie, CSRF-токена и других деталей окружения
        // (FR-NTF-214, FR-OPS-210).
        then: function (): void {
            Route::get('/health', HealthController::class);

            // Метрики Prometheus (FR-OPS-501, FR-OPS-509): маршрут закрыт
            // сервисным токеном и живёт вне публичного API монолита.
            Route::get('/metrics', MetricsController::class)
                ->middleware(AuthenticateServiceToken::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Сквозной идентификатор запроса действует на любом маршруте, включая
        // /health и ошибки (FR-OPS-201). Значение приходит от монолита
        // заголовком X-Request-Id, иначе создаётся новый UUID.
        $middleware->prepend(CorrelationId::class);

        // Сервисный токен закрывает служебные маршруты API (FR-OPS-206).
        $middleware->alias(['service-token' => AuthenticateServiceToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Единый формат ошибок API: application/problem+json (ЧТЗ 8.5).
        $exceptions->render(static function (Throwable $exception, Request $request) {
            return (new ProblemRenderer)->render($exception, $request);
        });
    })->create();
