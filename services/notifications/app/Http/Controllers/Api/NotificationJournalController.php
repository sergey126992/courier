<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\Api\ApiResponse;
use App\Support\Api\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Журнал уведомлений сервиса (ЧТЗ 8.1, 8.6, FR-NTF-275).
 *
 * Постраничная выдача с фильтрами `status`, `channel`, `from`, `to`
 * и курсором. Ответ — единый конверт `{"data": [...], "next_cursor": ...}`;
 * персональных данных в строках нет (FR-NTF-275).
 */
final class NotificationJournalController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(self::statuses())],
            'channel' => ['sometimes', 'string', Rule::in(self::channels())],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxLimit()],
            'cursor' => ['sometimes', 'string'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::defaultLimit());
        $query = Notification::query();

        if (isset($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if (isset($validated['channel'])) {
            $query->where('channel', (string) $validated['channel']);
        }

        if (isset($validated['from'])) {
            $query->where('created_at', '>=', self::moment((string) $validated['from']));
        }

        if (isset($validated['to'])) {
            $query->where('created_at', '<=', self::moment((string) $validated['to']));
        }

        $cursor = Cursor::decode($validated['cursor'] ?? null);

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        // Одна лишняя строка показывает, есть ли следующая страница.
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        if ($hasMore) {
            $rows = $rows->take($limit);
        }

        $last = $rows->last();

        return ApiResponse::json([
            'data' => $rows
                ->map(static fn (Notification $notification): array => NotificationResource::toArray($notification))
                ->values()
                ->all(),
            'next_cursor' => $hasMore && $last instanceof Notification ? Cursor::encode((int) $last->getKey()) : null,
        ]);
    }

    /**
     * Допустимые статусы фильтра: набор домена, а не литералы.
     *
     * @return list<string>
     */
    private static function statuses(): array
    {
        return array_map(static fn (NotificationStatus $status): string => $status->value, NotificationStatus::cases());
    }

    /**
     * Допустимые каналы фильтра.
     *
     * @return list<string>
     */
    private static function channels(): array
    {
        return array_map(static fn (NotificationChannel $channel): string => $channel->value, NotificationChannel::cases());
    }

    private static function defaultLimit(): int
    {
        return max(1, (int) config('notifications.api.page_limit', 1));
    }

    private static function maxLimit(): int
    {
        return max(self::defaultLimit(), (int) config('notifications.api.max_page_limit', 1));
    }

    /**
     * Момент фильтра в UTC: колонки хранятся в timestamptz (ЧТЗ 7, п. 5).
     */
    private static function moment(string $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:sP');
    }
}
