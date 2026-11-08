<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверяет структуру единого ответа об ошибке API.
 *
 * @param TestResponse<Response> $response
 */
function assertProblemResponse(TestResponse $response, string $code, int $status): void
{
    $response->assertStatus($status);
    $response->assertHeader('Content-Type', 'application/problem+json');
    $response->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code', 'details']);

    expect($response->json('code'))->toBe($code)
        ->and($response->json('status'))->toBe($status)
        ->and((string) $response->json('type'))->toContain($code)
        ->and((string) $response->json('title'))->not->toBe('')
        ->and((string) $response->json('detail'))->not->toBe('')
        ->and((string) $response->json('instance'))->not->toBe('')
        ->and($response->json('details'))->toBeArray();
}

it('отдаёт 401 в едином формате', function (): void {
    Route::middleware('auth:sanctum')->get('/api/v1/test/protected', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/api/v1/test/protected');

    assertProblemResponse($response, 'INVALID_CREDENTIALS', 401);
    expect($response->json('instance'))->toBe('/api/v1/test/protected');
});

it('отдаёт 403 в едином формате', function (): void {
    Route::get('/api/v1/test/forbidden', fn () => abort(403));

    assertProblemResponse($this->getJson('/api/v1/test/forbidden'), 'FORBIDDEN', 403);
});

it('отдаёт 404 в едином формате', function (): void {
    assertProblemResponse($this->getJson('/api/v1/test/unknown'), 'NOT_FOUND', 404);
});

it('отдаёт 422 с перечнем полей', function (): void {
    $response = $this->postJson('/api/v1/auth/register', []);

    assertProblemResponse($response, 'VALIDATION_FAILED', 422);

    $details = (array) $response->json('details');
    $fields = array_map(static fn (array $item): string => (string) $item['field'], $details);

    expect($fields)->toContain('name')->toContain('email')->toContain('password');
    expect($details[0])->toHaveKeys(['field', 'message']);
});

it('отдаёт 429 с заголовком повтора', function (): void {
    Route::middleware('throttle:1,1')->get('/api/v1/test/limited', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/v1/test/limited')->assertOk();

    $response = $this->getJson('/api/v1/test/limited');

    assertProblemResponse($response, 'RATE_LIMITED', 429);
    $response->assertHeader('Retry-After');
});

it('отдаёт 500 без трассировки и внутренних сообщений', function (): void {
    Route::get('/api/v1/test/failure', function (): never {
        throw new RuntimeException('секретная внутренняя ошибка');
    });

    $response = $this->getJson('/api/v1/test/failure');

    assertProblemResponse($response, 'INTERNAL_ERROR', 500);

    $body = (string) $response->getContent();

    expect($body)->not->toContain('секретная внутренняя ошибка')
        ->and($body)->not->toContain('trace')
        ->and($body)->not->toContain('RuntimeException');
});
