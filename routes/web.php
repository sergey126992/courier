<?php

declare(strict_types=1);

use App\Http\Controllers\PreviewController;
use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\Admin\NotificationJournalController;
use App\Http\Controllers\Web\Admin\NotificationRetryController;
use App\Http\Controllers\Web\Admin\NotificationServiceDlqReplayController;
use App\Http\Controllers\Web\Admin\NotificationServicePageController;
use App\Http\Controllers\Web\Admin\PushSimulatorController;
use App\Http\Controllers\Web\Auth\ForgotPasswordController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\LogoutController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\ResendVerificationController;
use App\Http\Controllers\Web\Auth\ResetPasswordController;
use App\Http\Controllers\Web\Auth\VerifyEmailController;
use App\Http\Controllers\Web\CabinetOrderController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PublicTrackingController;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use Illuminate\Support\Facades\Route;

// Главная страница: гость видит лендинг, авторизованный уходит в свой раздел
// по конфигурации роли (FR-UI-117, ЧТЗ 8.4).
Route::get('/', HomeController::class)->name('home');

// Вход: имя `login` обязательно — адрес гостя строится в bootstrap/app.php
// через route('login'). POST-маршрут — тот же вход без Livewire (ЧТЗ 8.1).
Route::middleware('guest')->group(function (): void {
    Route::get('/login', static fn () => view('auth.login'))->name('login');
    Route::post('/login', LoginController::class)->name('login.store');

    // Регистрация отправителя: валидация и операция общие с API (FR-UI-105).
    Route::get('/register', static fn () => view('auth.register'))->name('register');
    Route::post('/register', RegisterController::class)
        ->middleware('throttle:auth-register')
        ->name('register.store');

    // Запрос ссылки сброса: ответ не раскрывает существование аккаунта (FR-UI-107).
    Route::get('/forgot-password', static fn () => view('auth.forgot-password'))->name('password.request');
    Route::post('/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:auth-password-forgot')
        ->name('password.email');

    // Ссылка из письма: GET открывает форму, POST меняет пароль.
    Route::match(['get', 'post'], '/reset-password/{token}', ResetPasswordController::class)
        ->middleware('signed')
        ->name('password.reset');
});

// Выход завершает веб-сессию; повторный POST без сессии уходит на вход (FR-UI-108).
Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

// Подтверждение почты: ссылку могут открыть в другом браузере, поэтому `auth` нет.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware('signed')
    ->name('verification.verify.web');

// Публичная страница отслеживания: доступ даёт только подписанная ссылка,
// поэтому `auth` и `verified` здесь нет (FR-UI-129, FR-TRK-134, ЧТЗ 8.1).
// Частоту обращений ограничивает тот же лимит, что и у API (FR-TRK-136).
Route::get('/tracking/{code}', PublicTrackingController::class)
    ->middleware(['signed', 'throttle:tracking-public'])
    ->name('tracking.public.web');

// Напоминание и повторная отправка письма: только авторизованному (FR-UI-106).
Route::middleware('auth')->group(function (): void {
    // Профиль: виден всем авторизованным, права на правку проверяет политика (FR-UI-118).
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/account', [AccountController::class, 'update'])->name('account.update');

    // Настройки уведомлений: отдельная страница кабинета (FR-UI-401, ЧТЗ 8.2).
    // Вход — карточка-ссылка в профиле, новых пунктов меню нет (FR-UI-411).
    // Данные читает Livewire через сервис оповещений, база монолита не нужна.
    Route::get('/account/notifications', static fn () => view('account.notifications'))
        ->middleware('verified')
        ->name('account.notifications');

    // Кабинет отправителя: список своих заказов с фильтрами и курсором
    // (FR-UI-124, 125; маршрут — ЧТЗ 8.1). Доступ — только роли, которой
    // разрешено создавать заказы: диспетчер и курьер получают 403.
    Route::get('/cabinet/orders', static fn () => view('cabinet.orders.index'))
        ->middleware(['verified', 'can:create,'.DeliveryOrder::class])
        ->name('cabinet.orders');

    // Расчёт и создание заказа: та же роль и подтверждение почты (ЧТЗ 8.1).
    // Ничего не меняет через GET: создание выполняет действие Livewire.
    Route::get('/cabinet/orders/create', static fn () => view('cabinet.orders.create'))
        ->middleware(['verified', 'can:create,'.DeliveryOrder::class])
        ->name('cabinet.orders.create');

    // Карточка заказа: владелец или диспетчер (FR-UI-126, ЧТЗ 8.1).
    // Роль без доступа к заказам получает 403 на маршруте (`can:viewAny`),
    // а чужой заказ контроллер превращает в тот же 404, что и несуществующий,
    // иначе `can:view,order` раскрывал бы существование чужого заказа.
    Route::get('/cabinet/orders/{order}', [CabinetOrderController::class, 'show'])
        ->middleware(['verified', 'can:viewAny,'.DeliveryOrder::class])
        ->name('cabinet.orders.show');

    // Трекинг заказа: блоки отслеживания и публичной ссылки (FR-UI-128, 129).
    // Чужой заказ превращается в 404 тем же способом, что и в карточке.
    Route::get('/cabinet/orders/{order}/tracking', [CabinetOrderController::class, 'tracking'])
        ->middleware(['verified', 'can:viewAny,'.DeliveryOrder::class])
        ->name('cabinet.orders.tracking');

    // Панель диспетчера: очередь заказов в статусе «создан» (FR-UI-131…133;
    // маршрут — ЧТЗ 8.1). Доступ — по праву назначения: отправитель, курьер
    // и администратор получают 403, гость уходит на вход.
    Route::get('/dispatcher', static fn () => view('dispatcher.queue'))
        ->middleware(['verified', 'can:assign,'.Assignment::class])
        ->name('dispatcher.queue');

    // Карточка заказа диспетчера: кандидаты, назначение и переназначение
    // (FR-UI-134…138; маршрут — ЧТЗ 8.1). Роль отсекается правом
    // `viewCandidates` (отправитель, курьер и администратор получают 403),
    // несуществующий заказ — общий 404, гость — 302 на вход.
    Route::get('/dispatcher/orders/{order}', static fn (DeliveryOrder $order) => view('dispatcher.orders.show', ['order' => $order]))
        ->middleware(['verified', 'can:viewCandidates,order'])
        ->name('dispatcher.orders.show');

    // Административные страницы уведомлений (ЧТЗ 8.2, FR-NTF-293): журнал
    // необработанных, повтор и симулятор push. Доступ — только администратор
    // (Gate `notifications:manage`), abilities токенов не расширяются.
    Route::get('/notifications/failed', NotificationJournalController::class)
        ->middleware(['verified', 'can:notifications:manage'])
        ->name('notifications.failed');

    Route::post('/notifications/{id}/retry', NotificationRetryController::class)
        ->middleware(['verified', 'can:notifications:manage'])
        ->whereNumber('id')
        ->name('notifications.retry');

    Route::get('/notifications/push-simulator', PushSimulatorController::class)
        ->middleware(['verified', 'can:notifications:manage'])
        ->name('notifications.push-simulator');

    // Страница «Сервис оповещений» с вкладкой DLQ и прокси-повтор записи
    // (ЧТЗ 8.2, FR-NTF-511). Доступ — только администратор через тот же
    // Gate; abilities токенов не расширяются.
    Route::get('/notifications/service', NotificationServicePageController::class)
        ->middleware(['verified', 'can:notifications:manage'])
        ->name('notifications.service');

    Route::post('/notifications/service/dlq/{id}/replay', NotificationServiceDlqReplayController::class)
        ->middleware(['verified', 'can:notifications:manage'])
        ->whereNumber('id')
        ->name('notifications.service.dlq.replay');

    Route::get('/email/verify', static fn () => view('auth.verify-notice'))->name('verification.notice');
    Route::post('/email/verification-notification', ResendVerificationController::class)
        ->middleware('throttle:auth-email-resend')
        ->name('verification.send');
});

// Preview-раздел: каталог demo и demo-страницы. Доступен только локально:
// guard зашит в config('preview.enabled') и в PreviewController (404 вне local).
Route::prefix('preview')
    ->name('preview.')
    ->group(function (): void {
        Route::get('/', [PreviewController::class, 'index'])->name('index');
        Route::get('{demo}', [PreviewController::class, 'show'])->name('demo');
    });
