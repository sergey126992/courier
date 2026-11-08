<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use InvalidArgumentException;

/**
 * Выбор клиента Bot API Telegram по конфигурации (FR-NTF-433).
 *
 * В рабочем окружении это HTTP-адаптер: базовый URL, токен бота, таймаут
 * и число повторов берутся только из конфигурации. Значение `fake`
 * подставляет клиент без сети для тестов и демонстрации; так же устроен
 * `PushProviderFactory` — точка входа для замены реализации на M13.
 *
 * Неизвестное значение останавливает отправку ошибкой конфигурации,
 * а не тихим переходом в сеть.
 */
final class TelegramBotClientFactory
{
    /**
     * @throws InvalidArgumentException значение ключа `telegram.client` не поддерживается
     */
    public function make(): TelegramBotClient
    {
        $client = strtolower(trim((string) config('notifications.telegram.client')));

        return match ($client) {
            '', 'http' => new HttpTelegramBotClient(
                token: (string) config('notifications.telegram.bot_token', ''),
                apiUrl: (string) config('notifications.telegram.api_url', ''),
                timeoutSeconds: (int) config('notifications.telegram.timeout_seconds', 1),
                retries: (int) config('notifications.telegram.retries', 1),
            ),
            'fake' => new FakeTelegramBotClient,
            default => throw new InvalidArgumentException(sprintf(
                'Клиент Bot API Telegram «%s» не поддерживается.',
                $client,
            )),
        };
    }
}
