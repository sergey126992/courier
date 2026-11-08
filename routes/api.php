<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CourierLocationController;
use App\Http\Controllers\Api\V1\EstimateController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderTrackingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicTrackingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TrackingLinkController;
use App\Http\Controllers\Api\V1\TrackingPointController;
use App\Http\Controllers\Api\V1\ZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Префикс /api/v1 задаётся в bootstrap/app.php.
|
*/

Route::prefix('auth')->group(function (): void {
    // Регистрация гостя: лимит по IP, значения — config/rate_limits.php.
    Route::post('register', RegisterController::class)
        ->middleware('throttle:auth-register')
        ->name('auth.register');

    // Вход: лимит считает только неудачные попытки и живёт в операции входа.
    Route::post('login', LoginController::class)
        ->name('auth.login');

    // Выход: отзывается только токен текущего запроса.
    Route::post('logout', LogoutController::class)
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    // Запрос сброса пароля: ответ не раскрывает существование аккаунта.
    Route::post('password/forgot', ForgotPasswordController::class)
        ->middleware('throttle:auth-password-forgot')
        ->name('auth.password.forgot');

    // Установка нового пароля по подписанной ссылке из письма.
    Route::post('password/reset', ResetPasswordController::class)
        ->middleware('signed')
        ->name('auth.password.reset');

    // Ссылка из письма: POST по контракту API и GET для открытия в браузере.
    Route::match(['get', 'post'], 'email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('email/resend', ResendVerificationController::class)
        ->middleware(['auth:sanctum', 'throttle:auth-email-resend'])
        ->name('auth.email.resend');
});

// Личные данные: только собственный профиль, права проверяет политика UserPolicy.
Route::middleware('auth:sanctum')->group(function (): void {
    // Просмотр доступен и неподтверждённому: по ЧТЗ 5.4 он видит свой профиль
    // и состояние подтверждения почты.
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');

    // Изменение личных данных требует подтверждённой почты (ЧТЗ 5.1, шаг 5).
    Route::patch('profile', [ProfileController::class, 'update'])
        ->middleware('verified.api')
        ->name('profile.update');

    // Просмотр по идентификатору: чужой id неотличим от отсутствующего (404).
    Route::get('users/{user}', [ProfileController::class, 'showUser'])->name('users.show');

    // Предварительный расчёт: только подтверждённый отправитель, лимит — по пользователю.
    // Расстояние считает сервер по адресам забора и доставки (FR-GEO-113).
    Route::post('estimates', EstimateController::class)
        ->middleware(['verified.api', 'throttle:pricing-estimate'])
        ->name('estimates.store');

    // Создание заказа: подтверждённый отправитель, ключ повтора обязателен,
    // лимит частоты — по пользователю.
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware(['verified.api', 'throttle:orders-create', 'idempotency'])
        ->name('orders.store');

    // Отмена заказа: отправитель своего заказа или диспетчер, права — политика.
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->middleware('verified.api')
        ->name('orders.cancel');

    // Кандидаты на заказ: только диспетчер, лимит — config/rate_limits.php (FR-DSP-118).
    Route::get('orders/{order}/candidates', [OrderController::class, 'candidates'])
        ->middleware('throttle:dispatch-assign')
        ->name('orders.candidates');

    // Назначение и переназначение курьера: только диспетчер, общий лимит этапа.
    // Права проверяет политика AssignmentPolicy (FR-DSP-131…138).
    Route::post('orders/{order}/assign', [AssignmentController::class, 'assign'])
        ->middleware('throttle:dispatch-assign')
        ->name('orders.assign');
    Route::post('orders/{order}/reassign', [AssignmentController::class, 'reassign'])
        ->middleware('throttle:dispatch-assign')
        ->name('orders.reassign');

    // Назначения курьера: список и карточка, действия по своему назначению
    // (ЧТЗ 8.1, 8.3). Права проверяет политика AssignmentPolicy; чужое
    // назначение неотличимо от несуществующего.
    Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('assignments/{assignment}', [AssignmentController::class, 'show'])->name('assignments.show');
    Route::post('assignments/{assignment}/accept', [AssignmentController::class, 'accept'])
        ->middleware('throttle:courier-actions')
        ->name('assignments.accept');
    Route::post('assignments/{assignment}/pickup', [AssignmentController::class, 'pickup'])
        ->middleware('throttle:courier-actions')
        ->name('assignments.pickup');
    Route::post('assignments/{assignment}/deliver', [AssignmentController::class, 'deliver'])
        ->middleware('throttle:courier-actions')
        ->name('assignments.deliver');
    Route::post('assignments/{assignment}/fail', [AssignmentController::class, 'fail'])
        ->middleware('throttle:courier-actions')
        ->name('assignments.fail');

    // Геоточка по своему назначению: принято или забрано, лимит — по курьеру
    // (ЧТЗ 8.4, FR-TRK-101…108). Ответ 202: пересчёт трекинга идёт при чтении.
    Route::post('assignments/{assignment}/points', [TrackingPointController::class, 'store'])
        ->middleware('throttle:courier-points')
        ->name('assignments.points.store');

    // Чтение заказов: отправитель видит свои, диспетчер — все, права — политика.
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Трекинг заказа: владелец и диспетчер, права — политика заказа
    // (ЧТЗ 8.5, FR-TRK-121…128). Чтение заказа лимитом не ограничено.
    Route::get('orders/{order}/tracking', OrderTrackingController::class)->name('orders.tracking');

    // Публичная ссылка трекинга: владелец заказа или диспетчер, лимит —
    // по пользователю (ЧТЗ 8.6, FR-TRK-131…133). Чужой заказ — 404.
    Route::get('orders/{order}/tracking-link', TrackingLinkController::class)
        ->middleware('throttle:tracking-link')
        ->name('orders.tracking-link');

    // Отчёт по заказам: только администратор, права проверяет Gate reports:read
    // (ЧТЗ 8.1, FR-RPT-101…107). Лимит частоты — по пользователю, не по адресу.
    Route::get('reports/orders', [ReportController::class, 'orders'])
        ->middleware(['verified.api', 'throttle:reports_read'])
        ->name('reports.orders');

    // Смена курьера: открытие, текущая смена и закрытие (FR-DSP-101…106).
    // Права проверяет политика CourierShiftPolicy, лимит — config/rate_limits.php.
    Route::post('courier/shifts', [ShiftController::class, 'store'])
        ->middleware('throttle:courier-actions')
        ->name('courier.shifts.store');
    Route::get('courier/shifts/current', [ShiftController::class, 'current'])
        ->name('courier.shifts.current');
    Route::post('courier/shifts/{shift}/close', [ShiftController::class, 'close'])
        ->name('courier.shifts.close');

    // Heartbeat позиции курьера: только при открытой смене, история геоточек
    // не пишется (ЧТЗ 8.4, FR-TRK-113, FR-TRK-114).
    Route::post('courier/location', [CourierLocationController::class, 'store'])
        ->middleware('throttle:courier-heartbeat')
        ->name('courier.location.store');

    // Справочник зон: только администратор, права проверяет политика ServiceZonePolicy.
    // Удаления нет — зона деактивируется через PATCH (FR-GEO-105).
    Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');
    Route::post('zones', [ZoneController::class, 'store'])->name('zones.store');
    Route::patch('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
});

// Публичный трекинг по подписанной ссылке — единственный маршрут без токена
// (ЧТЗ 8.7). Подпись и срок проверяет middleware signed, частоту обращений
// по IP ограничивает throttle (FR-TRK-135, FR-TRK-136).
Route::get('tracking/{code}', PublicTrackingController::class)
    ->middleware(['signed', 'throttle:tracking-public'])
    ->name('tracking.public');
