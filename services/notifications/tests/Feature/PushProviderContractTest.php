<?php

declare(strict_types=1);

use App\Domain\Notifications\Exceptions\NotificationBusinessException;
use App\Domain\Notifications\NotificationTemplate;
use App\Logging\TechnicalLogger;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Services\Notifications\Channels\FakePushProvider;
use App\Services\Notifications\Channels\FcmPushProvider;
use App\Services\Notifications\Channels\PushProvider;
use App\Services\Notifications\DeviceTokens\DeviceTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Контракт PushProvider: fake и fcm (FR-NTF-532)
|--------------------------------------------------------------------------
|
| Оба провайдера проходят одинаковые кейсы: успешная отправка не бросает
| исключение, детерминированный отказ переводится в бизнес-ошибку. Значит,
| переключение провайдера не меняет PushChannel и вызывающий код.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();

    DeviceToken::factory()->create([
        'user_id' => 7,
        'token' => 'fcm-token-'.str_repeat('a', 40),
        'fingerprint' => DeviceToken::fingerprint('fcm-token-'.str_repeat('a', 40)),
    ]);
});

/**
 * Провайдер выбранной реализации.
 */
function contractProvider(string $kind): PushProvider
{
    if ($kind === 'fake') {
        return new FakePushProvider;
    }

    return new FcmPushProvider(
        deviceTokens: app(DeviceTokenStore::class),
        accessToken: static fn (): string => 'ya29.test',
        logger: app(TechnicalLogger::class),
        projectId: 'demo-project',
        timeoutSeconds: 5,
        retryTimes: 0,
        retrySleepMs: 0,
        sleeper: static function (int $milliseconds): void {},
    );
}

it('одинаково принимает сообщение', function (string $kind): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1'])]);

    $notificationId = (int) Notification::factory()->push()->create(['recipient_id' => 7])->getKey();

    contractProvider($kind)->send($notificationId, 7, NotificationTemplate::OrderDeliveredPush, 'Заказ 42 доставлен');

    expect(true)->toBeTrue();
})->with(['fake', 'fcm']);

it('одинаково переводит детерминированный отказ в бизнес-ошибку', function (string $kind): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400)]);

    $provider = contractProvider($kind);

    if ($provider instanceof FakePushProvider) {
        $provider->failRecipients = [7];
    }

    $notificationId = (int) Notification::factory()->push()->create(['recipient_id' => 7])->getKey();

    expect(static function () use ($provider, $notificationId): void {
        $provider->send($notificationId, 7, NotificationTemplate::OrderDeliveredPush, 'Заказ 42 доставлен');
    })->toThrow(NotificationBusinessException::class);
})->with(['fake', 'fcm']);
