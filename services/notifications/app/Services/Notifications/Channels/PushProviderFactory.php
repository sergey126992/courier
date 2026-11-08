<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Logging\TechnicalLogger;
use App\Services\Notifications\DeviceTokens\DeviceTokenStore;
use InvalidArgumentException;

/**
 * Выбор push-провайдера по конфигурации (FR-NTF-255).
 *
 * На этапе M11 допустимо единственное значение `fake` (или пустое значение —
 * провайдер по умолчанию). Неизвестное значение останавливает отправку
 * ошибкой конфигурации, а не тихим переходом в сеть: внешних провайдеров
 * в M11 нет.
 */
final class PushProviderFactory
{
    /**
     * @throws InvalidArgumentException значение ключа `push_provider` не поддерживается
     */
    public function make(): PushProvider
    {
        $provider = strtolower(trim((string) config('notifications.push.provider')));

        return match ($provider) {
            '', 'fake' => new FakePushProvider,
            'fcm' => $this->makeFcm(),
            default => throw new InvalidArgumentException(sprintf(
                'Push-провайдер «%s» не поддерживается.',
                $provider,
            )),
        };
    }

    /**
     * Реальный провайдер FCM: креды только из окружения, иначе ошибка
     * конфигурации, а не тихий переход в сеть (FR-NTF-522, FR-NTF-524).
     */
    private function makeFcm(): FcmPushProvider
    {
        $projectId = trim((string) config('notifications.push.fcm.project_id', ''));
        $credentialsPath = trim((string) config('notifications.push.fcm.credentials_path', ''));

        if ($projectId === '' || $credentialsPath === '') {
            throw new InvalidArgumentException(
                'Провайдер fcm не настроен: задайте идентификатор проекта и путь к service account JSON в окружении.',
            );
        }

        $timeoutSeconds = max(1, (int) config('notifications.push.fcm.timeout_seconds', 10));
        $source = new ServiceAccountFcmTokenSource($credentialsPath, $timeoutSeconds);

        $sleeper = static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };

        return new FcmPushProvider(
            deviceTokens: app(DeviceTokenStore::class),
            accessToken: static fn (): string => $source->accessToken(),
            logger: app(TechnicalLogger::class),
            projectId: $projectId,
            timeoutSeconds: $timeoutSeconds,
            retryTimes: max(0, (int) config('notifications.push.fcm.retry_times', 2)),
            retrySleepMs: max(0, (int) config('notifications.push.fcm.retry_sleep_ms', 200)),
            sleeper: $sleeper,
        );
    }
}
