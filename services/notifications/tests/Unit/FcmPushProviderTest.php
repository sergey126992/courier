<?php

declare(strict_types=1);

use App\Domain\Notifications\Exceptions\NotificationDeviceUnregistered;
use App\Domain\Notifications\Exceptions\NotificationPushTokenMissing;
use App\Domain\Notifications\Exceptions\PushProviderRejected;
use App\Domain\Notifications\Exceptions\PushProviderUnavailable;
use App\Domain\Notifications\Exceptions\PushRateLimited;
use App\Domain\Notifications\NotificationTemplate;
use App\Logging\TechnicalLogContext;
use App\Logging\TechnicalLogFormatter;
use App\Logging\TechnicalLogger;
use App\Models\DeviceToken;
use App\Services\Notifications\Channels\FcmPushProvider;
use App\Services\Notifications\DeviceTokens\DeviceTokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| FCM HTTP v1 провайдер push (FR-NTF-521…523, 527, 529)
|--------------------------------------------------------------------------
|
| Сети нет: ответы FCM подменяются `Http::fake()`. Токен доступа приходит из
| замыкания, поэтому OAuth в этих проверках не участвует. Паузы между
| повторами записываются подставным «сном», реальных ожиданий нет.
|
*/

const FCM_TOKEN = 'device-token-abcdefghijklmnop';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Подставное хранилище токенов без базы.
 */
final class FcmFakeTokenStore implements DeviceTokenStore
{
    /**
     * @var list<string>
     */
    public array $revoked = [];

    /**
     * @param list<DeviceToken> $tokens
     */
    public function __construct(private readonly array $tokens) {}

    public function activeFor(int $userId): array
    {
        return $this->tokens;
    }

    public function revoke(DeviceToken $deviceToken): void
    {
        $this->revoked[] = $deviceToken->token;
    }
}

/**
 * @param list<DeviceToken> $tokens
 */
function fcmStore(array $tokens = []): FcmFakeTokenStore
{
    return new FcmFakeTokenStore($tokens);
}

/**
 * Несохранённый токен устройства для подставного хранилища.
 */
function fcmDeviceToken(string $token = FCM_TOKEN): DeviceToken
{
    $device = new DeviceToken;
    $device->user_id = 7;
    $device->token = $token;
    $device->fingerprint = DeviceToken::fingerprint($token);
    $device->platform = 'android';

    return $device;
}

/**
 * Провайдер с фиксированным токеном доступа и подставным сном.
 *
 * @param list<int> $sleeps записанные паузы между повторами, миллисекунды
 */
function fcmProvider(
    DeviceTokenStore $store,
    array &$sleeps,
    int $retryTimes = 0,
    int $retrySleepMs = 0,
): FcmPushProvider {
    $sleeper = static function (int $milliseconds) use (&$sleeps): void {
        $sleeps[] = $milliseconds;
    };

    return new FcmPushProvider(
        deviceTokens: $store,
        accessToken: static fn (): string => 'ya29.test',
        logger: new TechnicalLogger(new TechnicalLogContext),
        projectId: 'demo-project',
        timeoutSeconds: 5,
        retryTimes: $retryTimes,
        retrySleepMs: $retrySleepMs,
        sleeper: $sleeper,
    );
}

/**
 * Выполняет действие и возвращает выброшенное исключение.
 *
 * @param callable(): void $callback
 */
function fcmThrows(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('Ожидалось исключение, но его не было.');
}

it('отправляет сообщение в FCM с токеном устройства, заголовком и data', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1'])]);

    $sleeps = [];
    $provider = fcmProvider(fcmStore([fcmDeviceToken()]), $sleeps);

    $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Заказ 42 доставлен');

    Http::assertSent(static function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send'
            && $request->hasHeader('Authorization', 'Bearer ya29.test')
            && $request['message']['token'] === FCM_TOKEN
            && $request['message']['notification']['body'] === 'Заказ 42 доставлен'
            && $request['message']['data']['notification_id'] === '42'
            && $request['message']['data']['template'] === NotificationTemplate::OrderDeliveredPush->value;
    });
});

it('считает отсутствие токена не ошибкой отправки', function (): void {
    Http::fake();

    $sleeps = [];
    $provider = fcmProvider(fcmStore([]), $sleeps);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(NotificationPushTokenMissing::class);
    Http::assertNothingSent();
});

it('отзывает токен при ответе 404 UNREGISTERED без повтора', function (): void {
    Http::fake([
        'fcm.googleapis.com/*' => Http::response([
            'error' => [
                'status' => 'NOT_FOUND',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
            ],
        ], 404),
    ]);

    $store = fcmStore([fcmDeviceToken()]);
    $sleeps = [];
    $provider = fcmProvider($store, $sleeps, retryTimes: 2);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(NotificationDeviceUnregistered::class)
        ->and($store->revoked)->toBe([FCM_TOKEN]);

    Http::assertSentCount(1);
});

it('отзывает токен при ответе 410 UNREGISTERED', function (): void {
    Http::fake([
        'fcm.googleapis.com/*' => Http::response([
            'error' => ['details' => [['errorCode' => 'UNREGISTERED']]],
        ], 410),
    ]);

    $store = fcmStore([fcmDeviceToken()]);
    $sleeps = [];
    $provider = fcmProvider($store, $sleeps);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(NotificationDeviceUnregistered::class)
        ->and($store->revoked)->toBe([FCM_TOKEN]);
});

it('превращает 404 без UNREGISTERED в бизнес-отказ без отзыва', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404)]);

    $store = fcmStore([fcmDeviceToken()]);
    $sleeps = [];
    $provider = fcmProvider($store, $sleeps);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(PushProviderRejected::class)
        ->and($store->revoked)->toBe([]);
});

it('повторяет 429 в пределах лимита и передаёт Retry-After', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response('', 429, ['Retry-After' => '2'])]);

    $sleeps = [];
    $provider = fcmProvider(fcmStore([fcmDeviceToken()]), $sleeps, retryTimes: 2, retrySleepMs: 100);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(PushRateLimited::class)
        ->and($sleeps)->toBe([100, 100]);

    if (! $exception instanceof PushRateLimited) {
        throw new RuntimeException('Ожидался PushRateLimited.');
    }

    expect($exception->retryAfterSeconds())->toBe(2);
    Http::assertSentCount(3);
});

it('не превышает предел повторов для 5xx', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response('', 503)]);

    $sleeps = [];
    $provider = fcmProvider(fcmStore([fcmDeviceToken()]), $sleeps, retryTimes: 2, retrySleepMs: 50);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(PushProviderUnavailable::class)
        ->and($sleeps)->toBe([50, 50]);

    Http::assertSentCount(3);
});

it('считает таймаут инфраструктурной ошибкой с пределом повторов', function (): void {
    $calls = 0;

    Http::fake(static function () use (&$calls): never {
        $calls++;

        throw new ConnectionException('cURL error 28: timeout');
    });

    $sleeps = [];
    $provider = fcmProvider(fcmStore([fcmDeviceToken()]), $sleeps, retryTimes: 1, retrySleepMs: 10);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(PushProviderUnavailable::class)
        ->and($calls)->toBe(2);

    if (! $exception instanceof PushProviderUnavailable) {
        throw new RuntimeException('Ожидался PushProviderUnavailable.');
    }

    expect($exception->providerCode())->toBeNull();
});

it('переживает невалидный JSON ответа и берёт паузу из конфигурации', function (): void {
    Http::fake(['fcm.googleapis.com/*' => Http::response('not-json', 429)]);

    $sleeps = [];
    $provider = fcmProvider(fcmStore([fcmDeviceToken()]), $sleeps);

    $exception = fcmThrows(static function () use ($provider): void {
        $provider->send(42, 7, NotificationTemplate::OrderDeliveredPush, 'Текст');
    });

    expect($exception)->toBeInstanceOf(PushRateLimited::class);

    if (! $exception instanceof PushRateLimited) {
        throw new RuntimeException('Ожидался PushRateLimited.');
    }

    expect($exception->retryAfterSeconds())->toBe(1);
});

it('не пропускает полный токен в контекст журнала', function (): void {
    $filtered = TechnicalLogFormatter::filter([
        'token' => FCM_TOKEN,
        'fingerprint' => DeviceToken::fingerprint(FCM_TOKEN),
        'platform' => 'android',
    ]);

    expect($filtered)->toBe([
        'fingerprint' => DeviceToken::fingerprint(FCM_TOKEN),
        'platform' => 'android',
    ]);
});
