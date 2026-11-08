<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiProblem;
use App\Http\Resources\NotificationResource;
use App\Logging\TechnicalLogger;
use App\Models\Notification;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Ручной повтор уведомления (ЧТЗ 8.1, FR-NTF-276).
 *
 * Ответ `202` возвращает ту же строку журнала; `404` — уведомления нет;
 * `409` — оно уже отправлено. Повтор `failed` переводит запись в `error`
 * без новой строки и без сброса счётчика попыток: поллер увидит её готовой
 * к отправке, потому что строка обновлена после последней попытки.
 * Повтор уже повторяемой записи ничего не меняет (идемпотентность).
 */
final class NotificationRetryController extends Controller
{
    public function __construct(private readonly TechnicalLogger $logger) {}

    public function __invoke(int $id): JsonResponse
    {
        $notification = Notification::query()->find($id);

        if ($notification === null) {
            throw ApiProblem::notFound();
        }

        $status = $notification->statusValue();

        if ($status === NotificationStatus::Sent) {
            throw ApiProblem::alreadySent();
        }

        if ($status === NotificationStatus::Failed) {
            Notification::query()->whereKey($id)->update([
                'status' => NotificationStatus::Error->value,
                'updated_at' => now()->utc()->format('Y-m-d H:i:sP'),
            ]);

            $notification->refresh();
        }

        // Запись сервиса о повторе: correlation_id приходит из X-Request-Id
        // монолита, персональных данных в контексте нет (FR-OPS-201, FR-OPS-202).
        $this->logger->info('Повтор уведомления запрошен через API.', [
            'notification_id' => (int) $notification->getKey(),
            'order_id' => $notification->order_id,
            'recipient_id' => (int) $notification->recipient_id,
            'channel' => (string) $notification->channel,
            'status' => $notification->statusValue()->value,
            'attempt' => (int) $notification->attempts,
        ]);

        return ApiResponse::json(NotificationResource::toArray($notification), 202);
    }
}
