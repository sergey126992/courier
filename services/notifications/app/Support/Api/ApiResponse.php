<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * Единый формат ответов API сервиса (ЧТЗ 8.6).
 *
 * Стабильные флаги JSON: кириллица и слеши не экранируются, чтобы ответы
 * совпадали с примерами ЧТЗ и были читаемы в логах прокси.
 */
final class ApiResponse
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], self::JSON_FLAGS);
    }
}
