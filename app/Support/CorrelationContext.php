<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\RequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Сквозной идентификатор запроса для событий Outbox (FR-OPS-201).
 *
 * В HTTP-запросе значение берётся из контекста middleware RequestId, поэтому
 * correlation_id события совпадает с request_id в заголовке ответа и в
 * журнале. Вне HTTP (команды, расписание, очередь) идентификатора запроса
 * нет — генерируется новый UUID (ЧТЗ 12.2 п. 8).
 */
final class CorrelationContext
{
    /**
     * Действующий идентификатор запроса либо новый UUID вне HTTP.
     */
    public static function resolve(): string
    {
        return self::fromRequest() ?? (string) Str::uuid();
    }

    /**
     * request_id текущего запроса, если его назначил middleware.
     */
    private static function fromRequest(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        $value = $request->attributes->get(RequestId::CONTEXT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
