<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\DlqEntryResource;
use App\Models\DlqEntry;
use App\Support\Api\ApiResponse;
use App\Support\Api\DlqCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Список очереди необработанных (ЧТЗ 8.1, 8.4, FR-NTF-505, FR-NTF-512).
 *
 * Постраничная выдача с фильтрами `channel`, `state`, `from`, `to`
 * и курсором; порядок — по времени отказа. В ответе только снимок без
 * персональных данных. Размер порции и верхняя граница — из конфигурации
 * `notifications.dlq`, литералов в контроллере нет.
 */
final class DlqListController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['sometimes', 'string', Rule::in(self::channels())],
            'state' => ['sometimes', 'string', Rule::in(self::states())],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxLimit()],
            'cursor' => ['sometimes', 'string'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::defaultLimit());
        $query = DlqEntry::query();

        if (isset($validated['channel'])) {
            $query->where('channel', (string) $validated['channel']);
        }

        if (isset($validated['state'])) {
            $query->where('state', (string) $validated['state']);
        }

        if (isset($validated['from'])) {
            $query->where('failed_at', '>=', self::moment((string) $validated['from']));
        }

        if (isset($validated['to'])) {
            $query->where('failed_at', '<=', self::moment((string) $validated['to']));
        }

        $cursor = DlqCursor::decode($validated['cursor'] ?? null);

        if ($cursor !== null) {
            self::applyCursor($query, $cursor['failed_at'], $cursor['id']);
        }

        // Одна лишняя строка показывает, есть ли следующая страница.
        $rows = $query
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        if ($hasMore) {
            $rows = $rows->take($limit);
        }

        $last = $rows->last();

        return ApiResponse::json([
            'data' => $rows
                ->map(static fn (DlqEntry $entry): array => DlqEntryResource::toArray($entry))
                ->values()
                ->all(),
            'next_cursor' => $hasMore && $last instanceof DlqEntry
                ? DlqCursor::encode($last->failed_at, (int) $last->getKey())
                : null,
        ]);
    }

    /**
     * Продолжение выдачи после последней записи страницы.
     *
     * @param Builder<DlqEntry> $query
     */
    private static function applyCursor(Builder $query, string $failedAt, int $id): void
    {
        $query->where(static function (Builder $outer) use ($failedAt, $id): void {
            $outer->where('failed_at', '<', $failedAt)
                ->orWhere(static function (Builder $same) use ($failedAt, $id): void {
                    $same->where('failed_at', '=', $failedAt)->where('id', '<', $id);
                });
        });
    }

    /**
     * Допустимые каналы фильтра: набор домена, а не литералы.
     *
     * @return list<string>
     */
    private static function channels(): array
    {
        return array_map(static fn (NotificationChannel $channel): string => $channel->value, NotificationChannel::cases());
    }

    /**
     * Допустимые состояния разбора.
     *
     * @return list<string>
     */
    private static function states(): array
    {
        return array_map(static fn (DlqState $state): string => $state->value, DlqState::cases());
    }

    private static function defaultLimit(): int
    {
        return max(1, (int) config('notifications.dlq.page_limit', 1));
    }

    private static function maxLimit(): int
    {
        return max(self::defaultLimit(), (int) config('notifications.dlq.max_page_limit', 1));
    }

    /**
     * Момент фильтра в UTC: колонки хранятся в timestamptz (ЧТЗ 7.3 п. 2).
     */
    private static function moment(string $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:sP');
    }
}
