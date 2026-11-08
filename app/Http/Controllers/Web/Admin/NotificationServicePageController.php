<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Страница «Сервис оповещений» (ЧТЗ 8.2, FR-NTF-511).
 *
 * Доступ проверяет маршрут (`can:notifications:manage`); данные вкладки DLQ
 * готовит Livewire-компонент из служебного API сервиса, поэтому контроллер
 * только отдаёт страницу.
 */
final class NotificationServicePageController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.notifications.service');
    }
}
