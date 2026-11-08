<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Tracking\PublicTrackingSnapshot;
use App\Actions\Tracking\ReadPublicTracking;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Problem\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Публичная страница отслеживания (FR-UI-129, FR-TRK-134).
 *
 * Маршрут открывается без входа и без подтверждения почты: доступ даёт только
 * подписанная ссылка (middleware `signed`), частоту обращений ограничивает
 * `throttle:tracking-public`. Данные читает ReadPublicTracking, поэтому страница
 * показывает тот же белый список полей, что и API: статус, район доставки,
 * прогноз и время обновления — без адреса, координат, имени и номера заказа.
 */
final class PublicTrackingController extends Controller
{
    public function __invoke(string $code, ReadPublicTracking $reader): View
    {
        try {
            $snapshot = $reader->handle($code);
        } catch (ApiException $exception) {
            // Неизвестный код при валидной подписи — та же страница 404, что и
            // несуществующий адрес: наличие заказа по коду не раскрывается.
            if ($exception->errorCode !== ErrorCode::NotFound) {
                throw $exception;
            }

            abort(404);
        }

        return view('tracking.public', $this->present($snapshot));
    }

    /**
     * Значения страницы в скалярной форме: форматы дат готовит контроллер.
     *
     * @return array{tracking: PublicTrackingSnapshot, etaAt: string|null, updatedAt: string|null}
     */
    private function present(PublicTrackingSnapshot $snapshot): array
    {
        $format = (string) __('ui.formats.datetime');

        return [
            'tracking' => $snapshot,
            'etaAt' => $snapshot->etaAt === null ? null : Carbon::parse($snapshot->etaAt)->format($format),
            'updatedAt' => $snapshot->updatedAt === '' ? null : Carbon::parse($snapshot->updatedAt)->format($format),
        ];
    }
}
