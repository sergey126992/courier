<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Единственное место, где задаются флаги сериализации ответов API.
 *
 * Благодаря этому кириллица и слеши одинаково не экранируются и в успешных
 * ответах, и в ошибках; контроллеры флаги руками не выставляют.
 */
final class ApiResponse
{
    /**
     * Флаги кодирования JSON для всех ответов API.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Ответ с данными в виде массива.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers, self::JSON_FLAGS);
    }

    /**
     * Ответ на основе ресурса: обёртка data, дополнительные поля и статус.
     *
     * @template TResource of JsonResource
     *
     * @param TResource $resource
     * @param array<string, string> $headers
     */
    public static function resource(
        JsonResource $resource,
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        $response = $resource->response(request());

        $response->setStatusCode($status);
        $response->setEncodingOptions(self::JSON_FLAGS);
        $response->headers->add($headers);

        return $response;
    }
}
