<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Http\JsonResponse;

/**
 * Ответ `application/problem+json` (ЧТЗ 8.5, FR-OPS-206, FR-OPS-207).
 *
 * Поля стабильны: `type`, `title`, `status`, `code`. Токен, адреса,
 * сообщения провайдеров и трассировка в ответ не попадают.
 */
final class ProblemResponse
{
    public static function make(ErrorCode $code): JsonResponse
    {
        return response()->json(
            [
                'type' => $code->type(),
                'title' => $code->title(),
                'status' => $code->status(),
                'code' => $code->value,
            ],
            $code->status(),
            ['Content-Type' => 'application/problem+json'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
