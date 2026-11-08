<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\Exceptions\NotificationDeviceUnregistered;
use App\Domain\Notifications\Exceptions\NotificationInfrastructureException;
use App\Domain\Notifications\Exceptions\NotificationPushTokenMissing;
use App\Domain\Notifications\Exceptions\PushProviderRejected;
use App\Domain\Notifications\Exceptions\PushProviderUnavailable;
use App\Domain\Notifications\Exceptions\PushRateLimited;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationTemplate;
use App\Logging\TechnicalLogger;
use App\Models\DeviceToken;
use App\Services\Notifications\DeviceTokens\DeviceTokenStore;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Реальный push-провайдер FCM HTTP v1 (FR-NTF-521…523, FR-NTF-527…529).
 *
 * Отправка идёт методом `messages:send` через фасад `Http` без SDK. Адресация —
 * по активным device-токенам получателя: отсутствие токена не ошибка, а
 * бизнес-причина `NOTIFICATION_PUSH_TOKEN_MISSING`, по которой работает
 * замещение каналов M12 (FR-NTF-528).
 *
 * Внешний вызов ограничен таймаутом и числом повторов из конфигурации;
 * повторяются только идемпотентные ответы (`429`, `5xx`, таймаут). Бизнес-ответ
 * `404`/`410` с `UNREGISTERED` отзывает токен и не повторяется (FR-NTF-527).
 * `Retry-After` учитывается, но пауза внутреннего повтора не превышает
 * настроенный лимит; окончательная пауза передаётся отправителю через
 * `PushRateLimited` и `next_attempt_at`.
 *
 * Токен устройства и токен доступа не логируются: в журнал попадают только
 * `fingerprint` и `platform` (FR-NTF-529, FR-NTF-531).
 */
final class FcmPushProvider implements PushProvider
{
    /**
     * Адрес FCM HTTP v1; проект подставляется из конфигурации.
     */
    private const ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /**
     * @param Closure(): string $accessToken получение токена доступа OAuth2
     * @param Closure(int): void $sleeper пауза между повторами, миллисекунды
     */
    public function __construct(
        private readonly DeviceTokenStore $deviceTokens,
        private readonly Closure $accessToken,
        private readonly TechnicalLogger $logger,
        private readonly string $projectId,
        private readonly int $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMs,
        private readonly Closure $sleeper,
    ) {}

    public function send(int $notificationId, int $recipientId, NotificationTemplate $template, string $body): void
    {
        $tokens = $this->deviceTokens->activeFor($recipientId);

        if ($tokens === []) {
            // Не ошибка отправки: нет токена — работает замещение каналов M12.
            throw new NotificationPushTokenMissing;
        }

        try {
            $accessToken = ($this->accessToken)();
        } catch (Throwable $exception) {
            throw PushProviderUnavailable::credentials($exception);
        }

        $lastBusiness = null;
        $lastInfrastructure = null;

        foreach ($tokens as $token) {
            try {
                $this->deliver($notificationId, $template, $body, $token, $accessToken);

                return;
            } catch (NotificationDeviceUnregistered $exception) {
                $this->revoke($notificationId, $recipientId, $token);
                $lastBusiness = $exception;
            } catch (PushProviderRejected $exception) {
                $lastBusiness = $exception;
            } catch (NotificationInfrastructureException $exception) {
                $lastInfrastructure = $exception;
            }
        }

        // Инфраструктурный сбой важнее бизнес-отказа: повтор может доставить
        // сообщение через другой токен.
        if ($lastInfrastructure !== null) {
            throw $lastInfrastructure;
        }

        if ($lastBusiness !== null) {
            throw $lastBusiness;
        }

        throw new NotificationPushTokenMissing;
    }

    /**
     * Отправка на один токен с ограниченными повторами идемпотентных кодов.
     */
    private function deliver(
        int $notificationId,
        NotificationTemplate $template,
        string $body,
        DeviceToken $token,
        string $accessToken,
    ): void {
        $payload = $this->payload($notificationId, $template, $body, $token);
        $url = sprintf(self::ENDPOINT, $this->projectId);
        $attempts = max(1, $this->retryTimes + 1);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $isLast = $attempt === $attempts - 1;

            try {
                $response = Http::timeout(max(1, $this->timeoutSeconds))
                    ->withToken($accessToken)
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);
            } catch (Throwable $exception) {
                if ($isLast) {
                    throw PushProviderUnavailable::timeout($exception);
                }

                ($this->sleeper)($this->retrySleepMs);

                continue;
            }

            $status = $response->status();

            if ($response->successful()) {
                return;
            }

            if ($status === 404 || $status === 410) {
                if ($this->isUnregistered($response)) {
                    throw new NotificationDeviceUnregistered;
                }

                throw PushProviderRejected::status($status);
            }

            if ($status === 429) {
                $retryAfter = $this->retryAfterSeconds($response);

                if ($isLast) {
                    throw new PushRateLimited($retryAfter);
                }

                // Пауза внутреннего повтора не превышает лимит из конфигурации.
                ($this->sleeper)(min($retryAfter * 1000, $this->retrySleepMs));

                continue;
            }

            if ($status >= 500) {
                if ($isLast) {
                    throw PushProviderUnavailable::status($status);
                }

                ($this->sleeper)($this->retrySleepMs);

                continue;
            }

            // Прочие 4xx — детерминированный бизнес-отказ без повтора.
            throw PushProviderRejected::status($status);
        }

        throw PushProviderUnavailable::status(0);
    }

    /**
     * Тело запроса FCM: заголовок `notification` и `data` без персональных данных.
     *
     * @return array{message: array<string, mixed>}
     */
    private function payload(
        int $notificationId,
        NotificationTemplate $template,
        string $body,
        DeviceToken $token,
    ): array {
        $notification = ['body' => $body];
        $title = trim((string) config('app.name', ''));

        if ($title !== '') {
            $notification = ['title' => $title, 'body' => $body];
        }

        return [
            'message' => [
                'token' => $token->token,
                'notification' => $notification,
                'data' => [
                    'notification_id' => (string) $notificationId,
                    'template' => $template->value,
                ],
            ],
        ];
    }

    /**
     * Причина `UNREGISTERED` в теле ошибки FCM: токен больше не действителен.
     */
    private function isUnregistered(Response $response): bool
    {
        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['error']) || ! is_array($payload['error'])) {
            return false;
        }

        $details = $payload['error']['details'] ?? null;

        if (! is_array($details)) {
            return false;
        }

        foreach ($details as $detail) {
            if (is_array($detail) && ($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Пауза из `Retry-After` или `retryDelay`; иначе — лимит из конфигурации.
     */
    private function retryAfterSeconds(Response $response): int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header) && (int) $header > 0) {
            return (int) $header;
        }

        $payload = $response->json();

        if (is_array($payload) && isset($payload['error']) && is_array($payload['error'])) {
            $details = $payload['error']['details'] ?? null;

            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (is_array($detail) && isset($detail['retryDelay']) && is_string($detail['retryDelay'])) {
                        $seconds = (int) $detail['retryDelay'];

                        if ($seconds > 0) {
                            return $seconds;
                        }
                    }
                }
            }
        }

        return max(1, intdiv($this->retrySleepMs, 1000));
    }

    /**
     * Отзыв токена и запись в журнал без полного токена (FR-NTF-527, FR-NTF-529).
     */
    private function revoke(int $notificationId, int $recipientId, DeviceToken $token): void
    {
        $this->deviceTokens->revoke($token);

        $this->logger->warning('Токен устройства отозван провайдером push.', [
            'notification_id' => $notificationId,
            'user_id' => $recipientId,
            'channel' => 'push',
            'platform' => $token->platform,
            'fingerprint' => $token->fingerprint,
            'error_code' => NotificationErrorCode::NOTIFICATION_DEVICE_UNREGISTERED,
        ]);
    }
}
