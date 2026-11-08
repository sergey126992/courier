<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use Throwable;

/**
 * Фейковый клиент Telegram для тестов (ЧТЗ 9.5).
 *
 * Сети нет: тесты укладывают апдейты в очередь и читают отправленные
 * сообщения. Отправленные сообщения содержат `chat_id`, поэтому объект
 * используется только в тестах и в журнал не попадает.
 */
final class FakeTelegramBotClient implements TelegramBotClient
{
    /**
     * Апдейты, ожидающие выдачи.
     *
     * @var list<array<string, mixed>>
     */
    private array $updates = [];

    /**
     * Отправленные сообщения.
     *
     * @var list<array{chat_id: string, text: string}>
     */
    public array $sent = [];

    /**
     * Зарегистрированные вебхуки.
     *
     * @var list<array{url: string, secret: string}>
     */
    public array $webhooks = [];

    /**
     * Сколько раз вебхук удалялся.
     */
    public int $deleted = 0;

    /**
     * Ошибка, которую клиент сообщает вместо ответа.
     */
    public ?Throwable $failure = null;

    /**
     * Ответ `getMe` для диагностики; токена в нём нет (FR-OPS-415).
     *
     * @var array<string, mixed>
     */
    public array $me = ['id' => 1, 'is_bot' => true, 'username' => 'courier_bot'];

    /**
     * Сколько раз вызывался `getMe`.
     */
    public int $meCalls = 0;

    /**
     * @param list<array<string, mixed>> $updates
     */
    public function queue(array $updates): void
    {
        foreach ($updates as $update) {
            $this->updates[] = $update;
        }
    }

    public function getUpdates(?int $offset, int $limit, int $timeoutSeconds): array
    {
        $this->throwFailure();

        $batch = array_splice($this->updates, 0, max(1, $limit));

        return array_values($batch);
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->throwFailure();

        $this->sent[] = ['chat_id' => $chatId, 'text' => $text];
    }

    public function getMe(): array
    {
        $this->throwFailure();

        $this->meCalls++;

        return $this->me;
    }

    public function setWebhook(string $url, string $secret): bool
    {
        $this->throwFailure();

        $this->webhooks[] = ['url' => $url, 'secret' => $secret];

        return true;
    }

    public function deleteWebhook(): bool
    {
        $this->throwFailure();

        $this->deleted++;

        return true;
    }

    private function throwFailure(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
