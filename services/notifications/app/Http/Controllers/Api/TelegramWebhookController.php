<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemResponse;
use App\Logging\TechnicalLogger;
use App\Services\Notifications\Telegram\UpdateHandler;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Приём апдейтов бота Telegram в режиме вебхука (FR-NTF-442, ЧТЗ 8.4).
 *
 * Маршрут регистрируется только при `telegram.updates_mode = webhook`.
 * Доступ закрыт секретом вебхука: значение сравнивается постоянным по
 * времени способом, неверный секрет даёт `403 TELEGRAM_WEBHOOK_SECRET_INVALID`
 * и апдейт отбрасывается. Секрет и `chat_id` в логи и ответы не попадают
 * (FR-OPS-411, FR-NTF-419).
 *
 * Повтор апдейта с тем же `update_id` второго эффекта не даёт: обработчик
 * одноразовый по токену привязки, поэтому повторное подтверждение отклоняется,
 * и привязка остаётся одна (ЧТЗ 8.4, ЧТЗ 12.2 п. 9).
 */
final class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly UpdateHandler $handler,
        private readonly TechnicalLogger $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->secretMatches($request)) {
            return ProblemResponse::make(ErrorCode::TelegramWebhookSecretInvalid);
        }

        $update = $request->json()->all();

        // Telegram считает доставку успешной только по коду 200, поэтому
        // ошибка обработки не превращается в повтор: апдейт уже получен,
        // а второй эффект недопустим (ЧТЗ 8.4).
        if (is_array($update) && $update !== []) {
            try {
                $this->handler->handle($update);
            } catch (Throwable) {
                $this->logger->error('Обработка апдейта Telegram завершилась ошибкой.', [
                    'channel' => NotificationChannel::Telegram->value,
                    'status' => 'error',
                ]);
            }
        }

        return ApiResponse::json(['ok' => true]);
    }

    /**
     * Постоянное по времени сравнение секрета вебхука (FR-NTF-442).
     */
    private function secretMatches(Request $request): bool
    {
        $expected = (string) config('notifications.telegram.webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $expected !== '' && hash_equals($expected, $provided);
    }
}
