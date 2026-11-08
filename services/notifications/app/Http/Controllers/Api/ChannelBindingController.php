<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelBindingResource;
use App\Models\ChannelBinding;
use App\Services\Notifications\Telegram\TelegramLinkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Привязки каналов и одноразовая привязка Telegram (ЧТЗ 8.1, 8.3).
 *
 * Маршруты закрыты сервисным токеном: единственный вызывающий — бот и
 * монолит (FR-NTF-421). В ответах нет адресов и токенов (FR-NTF-419):
 * привязка представлена каналом, статусом и моментами времени.
 *
 * Коды ответов (ЧТЗ 8.1): `start` — `201` или `422 TELEGRAM_CHANNEL_DISABLED`;
 * `confirm` — `200`, `409 TELEGRAM_LINK_TOKEN_INVALID`,
 * `410 TELEGRAM_LINK_TOKEN_EXPIRED`; `revoke` — `204` или
 * `409 TELEGRAM_BINDING_ABSENT`; список — `200`. Некорректные поля — `422`
 * в формате `application/problem+json` (ЧТЗ 8.4).
 */
final class ChannelBindingController extends Controller
{
    public function __construct(private readonly TelegramLinkService $links) {}

    /**
     * Выпуск токена привязки и deep-link (FR-NTF-411).
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $start = $this->links->start((int) $validated['user_id']);

        return ApiResponse::json([
            'deep_link' => $start->deepLink,
            'expires_at' => $start->expiresAt->utc()->toIso8601ZuluString(),
            'status' => $start->status->value,
        ], 201);
    }

    /**
     * Подтверждение привязки по токену и чату (FR-NTF-414…416).
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            // Идентификатор чата Telegram: отрицательный у групп, цифровой.
            'chat_id' => ['required', 'regex:/^-?\d{1,20}$/'],
        ]);

        $binding = $this->links->confirm(
            (string) $validated['token'],
            (string) $validated['chat_id'],
        );

        return ApiResponse::json(ChannelBindingResource::toArray($binding));
    }

    /**
     * Отвязка Telegram: строка остаётся в истории (FR-NTF-417).
     */
    public function revoke(Request $request): Response
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $this->links->revoke((int) $validated['user_id']);

        return response()->noContent();
    }

    /**
     * Статусы привязок пользователя без адресов (ЧТЗ 8.1, 8.3).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $userId = (int) $validated['user_id'];

        $rows = ChannelBinding::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();

        $bindings = $rows
            ->map(static fn (ChannelBinding $binding): array => ChannelBindingResource::toArray($binding))
            ->values()
            ->all();

        $hasTelegram = $rows->contains(
            static fn (ChannelBinding $binding): bool => (string) $binding->channel === NotificationChannel::Telegram->value,
        );

        // Ожидание подтверждения видно интерфейсу без адреса: токен выпущен,
        // активной привязки нет (ЧТЗ 5.2).
        if (! $hasTelegram && $this->links->pendingTokenExists($userId)) {
            $bindings[] = ChannelBindingResource::pending();
        }

        return ApiResponse::json(['bindings' => $bindings]);
    }
}
