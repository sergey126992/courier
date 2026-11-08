<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationAdminJournal;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Illuminate\View\View;

/**
 * Страница симулятора push (ЧТЗ 8.2, FR-NTF-254, FR-NTF-405).
 *
 * Показывает только идентификатор уведомления, тело сообщения и момент
 * записи. Недоступность сервиса оповещений — понятное состояние страницы,
 * а не `500` с трассировкой (FR-NTF-294).
 */
final class PushSimulatorController extends Controller
{
    public function __invoke(NotificationAdminJournal $journal): View
    {
        $rows = [];
        $unavailable = false;

        try {
            $rows = $journal->pushMessages()['rows'];
        } catch (NotificationServiceUnavailable) {
            $unavailable = true;
        }

        return view('admin.notifications.push-simulator', [
            'rows' => $rows,
            'unavailable' => $unavailable,
        ]);
    }
}
