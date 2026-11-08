<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Журнал необработанных уведомлений (ЧТЗ 8.2, FR-NTF-405).
 *
 * Доступ проверяет маршрут (`can:notifications:manage`); данные готовит
 * Livewire-компонент из сервиса оповещений — единственного источника,
 * поэтому контроллер только отдаёт страницу.
 */
final class NotificationJournalController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.notifications.failed');
    }
}
