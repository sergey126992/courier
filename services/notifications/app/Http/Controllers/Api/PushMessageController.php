<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PushMessageResource;
use App\Models\PushMessage;
use App\Support\Api\ApiResponse;
use App\Support\Api\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Журнал фейкового push-провайдера для страницы симулятора (FR-NTF-254).
 *
 * Выдача постраничная, без адресов и device-токенов: только идентификатор
 * уведомления, тело сообщения и момент записи.
 */
final class PushMessageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxLimit()],
            'cursor' => ['sometimes', 'string'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::defaultLimit());
        $query = PushMessage::query();

        $cursor = Cursor::decode($validated['cursor'] ?? null);

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        if ($hasMore) {
            $rows = $rows->take($limit);
        }

        $last = $rows->last();

        return ApiResponse::json([
            'data' => $rows
                ->map(static fn (PushMessage $message): array => PushMessageResource::toArray($message))
                ->values()
                ->all(),
            'next_cursor' => $hasMore && $last instanceof PushMessage ? Cursor::encode((int) $last->getKey()) : null,
        ]);
    }

    private static function defaultLimit(): int
    {
        return max(1, (int) config('notifications.api.page_limit', 1));
    }

    private static function maxLimit(): int
    {
        return max(self::defaultLimit(), (int) config('notifications.api.max_page_limit', 1));
    }
}
