<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP-адаптер Bot API Telegram (FR-NTF-433, FR-NTF-443).
 *
 * Базовый URL, токен бота, таймаут и число повторов приходят из конфигурации:
 * литеральных значений в коде нет. Токен — часть адреса метода Telegram,
 * поэтому в тексты исключений он не попадает: сообщения постоянные,
 * а статус ответа отдаётся отдельным методом (FR-OPS-411).
 */
final class HttpTelegramBotClient implements TelegramBotClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $apiUrl,
        private readonly int $timeoutSeconds,
        private readonly int $retries,
    ) {}

    public function getUpdates(?int $offset, int $limit, int $timeoutSeconds): array
    {
        $params = [
            'limit' => max(1, $limit),
            'timeout' => max(0, $timeoutSeconds),
        ];

        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        // Long polling ждёт дольше обычного запроса: клиентский таймаут
        // складывается из таймаута опроса и общего таймаута (FR-NTF-443).
        $result = $this->call(
            'getUpdates',
            $params,
            $this->timeoutSeconds + max(0, $timeoutSeconds),
        );

        if (! is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $update): bool => is_array($update)));
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    public function getMe(): array
    {
        $result = $this->call('getMe', []);

        return is_array($result) ? $result : [];
    }

    public function setWebhook(string $url, string $secret): bool
    {
        $params = ['url' => $url];

        if ($secret !== '') {
            $params['secret_token'] = $secret;
        }

        return $this->call('setWebhook', $params) === true;
    }

    public function deleteWebhook(): bool
    {
        return $this->call('deleteWebhook', []) === true;
    }

    /**
     * Вызов метода Bot API: единая проверка ответа и кода `ok`.
     *
     * @param array<string, mixed> $params
     *
     * @throws TelegramApiException запрос не выполнен или Telegram вернул ошибку
     */
    private function call(string $method, array $params, ?int $timeoutSeconds = null): mixed
    {
        try {
            $response = Http::timeout(max(1, $timeoutSeconds ?? $this->timeoutSeconds))
                ->retry(max(1, $this->retries), 100, throw: false)
                ->post($this->url($method), $params);
        } catch (Throwable) {
            throw new TelegramApiException('Запрос к API Telegram не выполнен.');
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            // Статус, описание и `retry_after` переносятся в исключение:
            // доменный код выбирает TelegramResponseMapper в одном месте.
            throw TelegramApiException::fromResponse($response->status(), $payload);
        }

        return $payload['result'] ?? null;
    }

    /**
     * Адрес метода: токен входит в путь и наружу не выводится.
     */
    private function url(string $method): string
    {
        return rtrim($this->apiUrl, '/').'/bot'.$this->token.'/'.$method;
    }
}
