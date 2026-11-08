<?php

declare(strict_types=1);

namespace App\Support\Docs;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Дописывает ошибочные ответы и обязательные заголовки по фактам маршрута.
 *
 * Правила не зависят от домена и не требуют ручных аннотаций: источник — middleware
 * маршрута, параметры пути и наличие тела запроса. Так описание остаётся верным
 * при добавлении нового маршрута без правки документации.
 */
final class ProblemResponsesTransformer implements OperationTransformer
{
    /**
     * Заголовок обязательного ключа повтора (FR-API-4, EnsureIdempotencyKey).
     */
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $middleware = array_values(array_map(
            static fn (mixed $item): string => (string) $item,
            $route->gatherMiddleware(),
        ));

        if ($this->hasMiddleware($middleware, 'auth:sanctum')) {
            $this->ensureResponse($operation, 401, 'Нет токена или токен недействителен.');
        }

        if ($this->hasMiddleware($middleware, 'idempotency')) {
            $this->addIdempotencyHeader($operation);
            $this->ensureResponse($operation, 409, 'Конфликт состояния или повтор запроса с другим телом.');
        }

        if (array_filter($middleware, static fn (string $item): bool => Str::startsWith($item, 'throttle:')) !== []) {
            $this->ensureResponse($operation, 429, 'Превышен лимит частоты; время повтора — в заголовке Retry-After.');
        }

        if ($route->parameterNames() !== []) {
            $this->ensureResponse($operation, 404, 'Объект не найден или принадлежит другому пользователю.');
        }

        if ($operation->requestBodyObject !== null) {
            $this->ensureResponse($operation, 422, 'Ошибка валидации или правило домена не выполнено.');
        }
    }

    /**
     * @param list<string> $middleware
     */
    private function hasMiddleware(array $middleware, string $expected): bool
    {
        return in_array($expected, $middleware, true);
    }

    /**
     * Ключ повтора обязателен: без него создание заказа отклоняется.
     */
    private function addIdempotencyHeader(Operation $operation): void
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->name === self::IDEMPOTENCY_HEADER) {
                return;
            }
        }

        $operation->addParameters([
            Parameter::make(self::IDEMPOTENCY_HEADER, 'header')
                ->required(true)
                ->description('Уникальный ключ повтора: повтор с тем же ключом и телом возвращает сохранённый ответ.')
                ->setSchema(Schema::fromType(
                    (new StringType)
                        ->setMin(1)
                        ->setMax(255),
                )),
        ]);
    }

    /**
     * Добавляет ответ, если такого кода ещё нет.
     */
    private function ensureResponse(Operation $operation, int $status, string $description): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && $response->code === $status) {
                return;
            }
        }

        $operation->addResponse(ProblemDetails::response($status, $description));
    }
}
