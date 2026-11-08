<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureIdempotencyKey;
use App\Http\Middleware\RequestId;
use App\Http\Problem\ApiException;
use App\Http\Problem\ProblemRenderer;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Сквозной идентификатор запроса: заголовок ответа и лог-контекст для
        // любого HTTP-ответа, включая 404 до маршрутизации и 500 (FR-OPS-101).
        // Глобальная регистрация единственная: в группах middleware не повторяется.
        $middleware->append(RequestId::class);

        $middleware->alias([
            // Проверка подтверждения почты для личных разделов API.
            'verified.api' => EnsureEmailIsVerified::class,
            // Идемпотентность создания заказа по заголовку Idempotency-Key.
            'idempotency' => EnsureIdempotencyKey::class,
        ]);

        // Гость API получает 401 в едином формате, а не редирект на страницу
        // входа: без заголовка Accept фреймворк иначе искал бы маршрут `login`
        // и отвечал 500 вместо отказа в авторизации.
        $middleware->redirectGuestsTo(
            static fn (Request $request): ?string => $request->is('api/*') ? null : route('login'),
        );

        // FR-UI-119: гость не отличает существующий заказ от несуществующего.
        // Проверка сессии должна идти раньше подстановки модели, иначе
        // SubstituteBindings отдаст 404 раньше, чем Authenticate уведёт на вход,
        // и по коду ответа можно будет перебирать идентификаторы.
        // Перечень повторяет порядок Laravel по умолчанию с явным Authenticate
        // перед SubstituteBindings: незаданные middleware сохраняют свои места.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            Authenticate::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ожидаемые отказы API — не повод писать в журнал ошибок.
        $exceptions->dontReport([ApiException::class]);

        // Единый формат ошибок API в одном месте; остальные ответы не трогаем.
        $exceptions->render(function (Throwable $exception, Request $request) {
            return app(ProblemRenderer::class)->render($exception, $request);
        });
    })->create();
