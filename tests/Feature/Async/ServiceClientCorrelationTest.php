<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use App\Services\Notifications\Service\NotificationServiceClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Сквозной correlation_id на границе монолит → сервис (FR-OPS-201)
|--------------------------------------------------------------------------
|
| Служебные вызовы сервиса передают X-Request-Id текущего запроса: запись
| журнала сервиса связывается с действием администратора. Вне HTTP
| идентификатора запроса нет, поэтому создаётся новый UUID (ЧТЗ 12.2 п. 8).
|
*/

/**
 * Клиент служебного API с фиксированными параметрами.
 */
function correlationServiceClient(): NotificationServiceClient
{
    return new NotificationServiceClient(
        baseUrl: 'http://notifications-app:8000',
        token: 'dev-notifications-token',
        timeoutSeconds: 5,
        retryTimes: 1,
        retrySleepMilliseconds: 100,
    );
}

it('передаёт request_id запроса монолита в заголовке X-Request-Id', function (): void {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $request = Request::create('/admin/notifications', 'GET');
    $request->attributes->set(RequestId::CONTEXT_KEY, 'req-0123456789abcdef');
    app()->instance('request', $request);

    correlationServiceClient()->failed(10);

    Http::assertSent(function (ClientRequest $sent): bool {
        return $sent->hasHeader(RequestId::HEADER)
            && $sent->header(RequestId::HEADER)[0] === 'req-0123456789abcdef';
    });
});

it('создаёт новый correlation_id вне HTTP-запроса', function (): void {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    correlationServiceClient()->failed(10);

    Http::assertSent(function (ClientRequest $sent): bool {
        $header = $sent->header(RequestId::HEADER)[0] ?? '';

        return Str::isUuid($header);
    });
});
