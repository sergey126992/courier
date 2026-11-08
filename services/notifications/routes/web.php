<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

// Пользовательского интерфейса в сервисе нет (ЧТЗ 3.4): корень отвечает
// минимальным служебным ответом без персональных данных и без деталей
// окружения. Состояние сервиса для мониторинга — /health: маршрут объявлен
// в bootstrap/app.php вне групп middleware (FR-NTF-214).
Route::get('/', static fn (): JsonResponse => response()->json([
    'service' => 'notifications',
]));
