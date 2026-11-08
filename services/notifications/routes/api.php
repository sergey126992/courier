<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ChannelBindingController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\DlqListController;
use App\Http\Controllers\Api\DlqReplayController;
use App\Http\Controllers\Api\NotificationJournalController;
use App\Http\Controllers\Api\NotificationRetryController;
use App\Http\Controllers\Api\PreferencesController;
use App\Http\Controllers\Api\PushMessageController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API сервиса (префикс /api/v1)
|--------------------------------------------------------------------------
|
| Служебный доступ к журналу уведомлений, ручному повтору и журналу
| фейкового push (FR-NTF-254, FR-NTF-275, FR-NTF-276). Все маршруты
| закрыты сервисным токеном (FR-OPS-206); /health остаётся без токена.
|
*/

Route::middleware('service-token')->group(function (): void {
    Route::get('notifications', NotificationJournalController::class)
        ->name('api.notifications.index');

    Route::post('notifications/{id}/retry', NotificationRetryController::class)
        ->whereNumber('id')
        ->name('api.notifications.retry');

    Route::get('push-messages', PushMessageController::class)
        ->name('api.push-messages.index');

    // Очередь необработанных M13 (ЧТЗ 8.1, FR-NTF-505, FR-NTF-506): чтение
    // порциями с фильтрами и повтор записи. Ответы без персональных данных,
    // ошибки — application/problem+json с кодами DLQ_ENTRY_*.
    Route::get('dlq', DlqListController::class)
        ->name('api.dlq.index');

    Route::post('dlq/{id}/replay', DlqReplayController::class)
        ->whereNumber('id')
        ->name('api.dlq.replay');

    // Device-токены для реального push (ЧТЗ 8.1, FR-NTF-525…529): регистрация
    // идемпотентна по токену, отзыв идемпотентен по идентификатору. Токен
    // в ответах не возвращается.
    Route::post('device-tokens', [DeviceTokenController::class, 'register'])
        ->name('api.device-tokens.register');

    Route::delete('device-tokens/{id}', [DeviceTokenController::class, 'revoke'])
        ->whereNumber('id')
        ->name('api.device-tokens.revoke');

    // Привязки каналов и одноразовая привязка Telegram (ЧТЗ 8.1, FR-NTF-411…417).
    // Подтверждение вызывает только бот: пользовательской сессии здесь нет
    // (FR-NTF-421), а адреса в ответах не выводятся (FR-NTF-419).
    Route::post('channel-bindings/telegram/start', [ChannelBindingController::class, 'start'])
        ->name('api.channel-bindings.telegram.start');

    Route::post('channel-bindings/telegram/confirm', [ChannelBindingController::class, 'confirm'])
        ->name('api.channel-bindings.telegram.confirm');

    Route::delete('channel-bindings/telegram', [ChannelBindingController::class, 'revoke'])
        ->name('api.channel-bindings.telegram.revoke');

    Route::get('channel-bindings', [ChannelBindingController::class, 'index'])
        ->name('api.channel-bindings.index');

    // Предпочтения получателя (ЧТЗ 8.1, FR-NTF-451…462): чтение эффективных
    // строк и сохранение набора. Пользовательских сессий у сервиса нет,
    // `user_id` передаёт монолит серверным клиентом (FR-NTF-461).
    Route::get('preferences', [PreferencesController::class, 'show'])
        ->name('api.preferences.show');

    Route::patch('preferences', [PreferencesController::class, 'update'])
        ->name('api.preferences.update');
});

// Вебхук бота существует только в режиме `webhook` (FR-NTF-442): в режиме
// long polling маршрута нет, поэтому апдейт нельзя прислать снаружи.
// Доступ закрыт секретом вебхука, а не сервисным токеном (ЧТЗ 8.1).
if (config('notifications.telegram.updates_mode') === 'webhook') {
    Route::post('telegram/webhook', TelegramWebhookController::class)
        ->name('api.telegram.webhook');
}
