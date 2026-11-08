<?php

declare(strict_types=1);

use App\Support\Docs\ProblemDetails;
use Illuminate\Support\Facades\Route;

/**
 * Описание API, зафиксированное командой api:docs.
 *
 * Тесты читают артефакт docs/api/openapi.json и сверяют его с фактическими
 * маршрутами: расхождение считается дефектом этапа M10 (FR-API-8).
 *
 * @return array<string, mixed>
 */
function apiDocsDocument(): array
{
    $path = base_path((string) config('scramble.export_path', 'docs/api/openapi.json'));

    expect(file_exists($path))->toBeTrue();

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $document;
}

/**
 * Маршруты API, которые обязан описывать артефакт.
 *
 * @return list<string>
 */
function apiDocsRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $routes[] = strtolower($method).' /'.ltrim(substr($route->uri(), strlen('api')), '/');
        }
    }

    sort($routes);

    return $routes;
}

it('публикует описание в формате OpenAPI 3.1', function (): void {
    $document = apiDocsDocument();

    expect($document['openapi'])->toStartWith('3.1')
        ->and($document['info']['title'])->not->toBeEmpty()
        ->and($document['info']['version'])->toBe((string) config('scramble.info.version'));
});

it('описывает каждый маршрут API', function (): void {
    $document = apiDocsDocument();

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];

    $documented = [];

    foreach ($paths as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtolower((string) $method).' '.$path;
        }
    }

    $routes = apiDocsRoutes();

    // Каждая описанная операция должна существовать как маршрут.
    foreach ($documented as $operation) {
        expect($routes)->toContain($operation);
    }

    // Каждый маршрут описан хотя бы одним методом: маршруты с двумя методами
    // (ссылка подтверждения почты) описываются один раз, и это допустимо.
    foreach ($routes as $route) {
        [$method, $path] = explode(' ', $route, 2);

        $matched = array_filter(
            $documented,
            static fn (string $operation): bool => str_ends_with($operation, ' '.$path),
        );

        expect($matched)->not->toBeEmpty("Маршрут {$route} не описан");
    }
});

it('применяет bearer-защиту к защищённым маршрутам и оставляет публичные открытыми', function (): void {
    $document = apiDocsDocument();

    expect($document['components']['securitySchemes'])->toHaveKey('http')
        ->and($document['security'])->toBe([['http' => []]])
        ->and($document['paths']['/v1/auth/login']['post']['security'])->toBe([])
        ->and($document['paths']['/v1/tracking/{code}']['get']['security'])->toBe([])
        ->and($document['paths']['/v1/orders']['get'])->not->toHaveKey('security');
});

it('требует ключ повтора при создании заказа и описывает конфликт', function (): void {
    $document = apiDocsDocument();
    $operation = $document['paths']['/v1/orders']['post'];

    $headers = array_map(
        static fn (array $parameter): string => $parameter['name'],
        array_values(array_filter(
            $operation['parameters'],
            static fn (array $parameter): bool => ($parameter['in'] ?? null) === 'header',
        )),
    );

    expect($headers)->toContain('Idempotency-Key')
        ->and($operation['responses'])->toHaveKeys(['201', '401', '409', '422', '429'])
        ->and($operation['responses']['409']['content'])->toHaveKey(ProblemDetails::MEDIA_TYPE)
        ->and($operation['responses']['422']['content'])->toHaveKey(ProblemDetails::MEDIA_TYPE);
});

it('описывает единый формат ошибки во всех ошибочных ответах', function (): void {
    $document = apiDocsDocument();

    expect($document['components']['schemas'])->toHaveKey(ProblemDetails::COMPONENT);

    $schema = $document['components']['schemas'][ProblemDetails::COMPONENT];

    expect($schema['properties'])->toHaveKeys(['type', 'title', 'status', 'detail', 'instance', 'code', 'details']);

    foreach ($document['components']['responses'] as $response) {
        expect($response['content'])->toHaveKey(ProblemDetails::MEDIA_TYPE);
    }
});

it('описывает поля ответа заказа по фактическому ресурсу', function (): void {
    $document = apiDocsDocument();

    $schema = $document['components']['schemas']['OrderResource'];

    expect($schema['properties'])->toHaveKeys([
        'number',
        'tracking_code',
        'status',
        'transport',
        'price',
        'breakdown',
        'pickup',
        'dropoff',
    ]);
});

it('не раскрывает секретов и реальных токенов', function (): void {
    $path = base_path((string) config('scramble.export_path', 'docs/api/openapi.json'));
    $content = (string) file_get_contents($path);

    expect($content)->not->toContain('Bearer ey')
        ->and($content)->not->toContain('"password":"')
        ->and($content)->not->toContain('sender@courier.test');
});
