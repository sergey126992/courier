<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Базовый ресурс ответа API.
 *
 * Нужен, чтобы контроллер возвращал конкретный тип ресурса: тогда описание OpenAPI
 * строится по фактическому классу и показывает поля ответа. Статус, заголовки
 * и флаги кодирования задаются здесь, а не в общем помощнике: поведение ответа
 * остаётся прежним (обёртка data, те же флаги, те же коды).
 *
 * @property-read mixed $resource Сериализуемая модель или снимок данных.
 */
abstract class ApiResource extends JsonResource
{
    private int $responseStatus = JsonResponse::HTTP_OK;

    /**
     * @var array<string, string>
     */
    private array $responseHeaders = [];

    /**
     * HTTP-статус ответа (например, 201 при создании).
     */
    public function withStatus(int $status): static
    {
        $this->responseStatus = $status;

        return $this;
    }

    /**
     * Дополнительные заголовки ответа (например, Location).
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->responseHeaders = $headers;

        return $this;
    }

    public function toResponse($request): JsonResponse
    {
        /** @var JsonResponse $response */
        $response = parent::toResponse($request);

        $response->setStatusCode($this->responseStatus);
        $response->setEncodingOptions(ApiResponse::JSON_FLAGS);

        if ($this->responseHeaders !== []) {
            $response->headers->add($this->responseHeaders);
        }

        return $response;
    }
}
