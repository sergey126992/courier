<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Сквозной идентификатор запроса (FR-OPS-101, FR-OPS-102).
 *
 * Middleware зарегистрирован глобально, поэтому проверяется и на обычном
 * ответе, и на 404 до маршрутизации: заголовок обязателен для любого ответа.
 */
function requestIdProbe(): void
{
    Route::middleware('api')->get('/api/v1/test/request-id', fn (): array => ['ok' => true]);
}

/**
 * Шаблон сгенерированного идентификатора: версия 4 UUID.
 */
function requestIdUuidPattern(): string
{
    return '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
}

it('принимает валидный X-Request-Id и возвращает его в ответе', function (): void {
    requestIdProbe();

    $response = $this->withHeader('X-Request-Id', 'abc12345.def-6')->getJson('/api/v1/test/request-id');

    $response->assertOk();
    expect($response->headers->get('X-Request-Id'))->toBe('abc12345.def-6');
});

it('формирует UUID, если заголовка нет', function (): void {
    requestIdProbe();

    $response = $this->getJson('/api/v1/test/request-id');

    $response->assertOk();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch(requestIdUuidPattern());
});

it('заменяет слишком короткий идентификатор на UUID', function (): void {
    requestIdProbe();

    $response = $this->withHeader('X-Request-Id', 'short')->getJson('/api/v1/test/request-id');

    $response->assertOk();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch(requestIdUuidPattern());
});

it('заменяет идентификатор с недопустимыми символами на UUID', function (): void {
    requestIdProbe();

    $response = $this->withHeader('X-Request-Id', 'bad id!<>')->getJson('/api/v1/test/request-id');

    $response->assertOk();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch(requestIdUuidPattern());
});

it('заменяет слишком длинный идентификатор на UUID', function (): void {
    requestIdProbe();

    $response = $this->withHeader('X-Request-Id', str_repeat('a', 129))->getJson('/api/v1/test/request-id');

    $response->assertOk();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch(requestIdUuidPattern());
});

it('добавляет request_id в лог-контекст запроса', function (): void {
    requestIdProbe();
    $logger = Log::spy();

    $this->withHeader('X-Request-Id', 'context12345')
        ->getJson('/api/v1/test/request-id')
        ->assertOk();

    $logger->shouldHaveReceived('withContext')
        ->withArgs(static fn (array $context): bool => ($context['request_id'] ?? null) === 'context12345')
        ->once();
});

it('возвращает принятый X-Request-Id на 404 по несуществующему маршруту', function (): void {
    $response = $this->withHeader('X-Request-Id', 'notfound12345')->getJson('/api/v1/does-not-exist');

    $response->assertNotFound();
    expect($response->headers->get('X-Request-Id'))->toBe('notfound12345');
});

it('формирует UUID на 404 без заголовка запроса', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch(requestIdUuidPattern());
});
