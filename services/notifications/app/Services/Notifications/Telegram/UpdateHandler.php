<?php

declare(strict_types=1);

namespace App\Services\Notifications\Telegram;

use App\Domain\Notifications\Exceptions\TelegramLinkTokenExpired;
use App\Domain\Notifications\Exceptions\TelegramLinkTokenInvalid;
use App\Domain\Notifications\NotificationChannel;
use App\Logging\TechnicalLogger;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;

/**
 * Единственный обработчик `/start <token>` для обоих режимов (FR-NTF-415, 442).
 *
 * Long polling и вебхук вызывают один и тот же метод, поэтому поведение
 * привязки не зависит от способа доставки апдейта. Действующий
 * неиспользованный токен подтверждает привязку; отсутствие токена и мусорное
 * значение дают общую подсказку, просроченный токен — отдельный ответ
 * «ссылка истекла» (ЧТЗ 5.4 п. 3); привязка в обоих случаях не создаётся,
 * прочие апдейты игнорируются.
 *
 * `chat_id` — персональные данные: он передаётся только в клиент Telegram
 * и в сервис привязки, а в журнал попадают лишь канал и статус
 * (FR-NTF-419, FR-OPS-412).
 */
final class UpdateHandler
{
    /**
     * Команда бота: `/start` и необязательный аргумент-токен.
     */
    private const START_PATTERN = '/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+(\S+))?\s*$/u';

    public function __construct(
        private readonly TelegramBotClient $client,
        private readonly TelegramLinkService $links,
        private readonly TechnicalLogger $logger,
    ) {}

    /**
     * Обрабатывает один апдейт Telegram.
     *
     * @param array<string, mixed> $update апдейт Bot API
     */
    public function handle(array $update): TelegramUpdateResult
    {
        $chatId = $this->chatId($update);
        $text = $this->messageText($update);

        if ($chatId === null || $text === null) {
            return TelegramUpdateResult::Ignored;
        }

        $token = $this->startPayload($text);

        if ($token === null) {
            return TelegramUpdateResult::Ignored;
        }

        if ($token === '') {
            $this->prompt($chatId);

            return TelegramUpdateResult::Prompted;
        }

        try {
            $this->links->confirm($token, $chatId);
        } catch (TelegramLinkTokenExpired) {
            // Истёкшая ссылка: привязка не создаётся и не меняется, а
            // пользователю нужен отдельный текст, чтобы выпустить новую
            // ссылку в настройках (ЧТЗ 5.4 п. 3).
            $this->reply($chatId, 'notifications.telegram.link_expired');

            return TelegramUpdateResult::Rejected;
        } catch (TelegramLinkTokenInvalid) {
            // Мусорный или уже использованный токен: второй эффект не
            // создаётся, ответ остаётся общей подсказкой (ЧТЗ 8.4).
            $this->prompt($chatId);

            return TelegramUpdateResult::Rejected;
        }

        $this->logger->info('Чат Telegram привязан к пользователю.', [
            'channel' => NotificationChannel::Telegram->value,
            'status' => 'verified',
        ]);

        return TelegramUpdateResult::Linked;
    }

    /**
     * Идентификатор чата из апдейта; null, если его нет.
     *
     * @param array<string, mixed> $update апдейт Bot API
     */
    private function chatId(array $update): ?string
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $chat = $message['chat'] ?? null;

        if (! is_array($chat)) {
            return null;
        }

        $id = $chat['id'] ?? null;

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return trim((string) $id);
    }

    /**
     * Текст сообщения; null, если сообщение не текстовое.
     *
     * @param array<string, mixed> $update апдейт Bot API
     */
    private function messageText(array $update): ?string
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $text = $message['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * Аргумент команды `/start`: `null` — это не `/start`, `''` — токена нет.
     */
    private function startPayload(string $text): ?string
    {
        if (preg_match(self::START_PATTERN, trim($text), $matches) !== 1) {
            return null;
        }

        return trim($matches[1] ?? '');
    }

    /**
     * Ответ-подсказка в чат: привязка не создаётся (FR-NTF-415).
     */
    private function prompt(string $chatId): void
    {
        $this->reply($chatId, 'notifications.telegram.link_hint');
    }

    /**
     * Текст бота из языкового файла: без персональных данных и токенов
     * (FR-NTF-419, FR-NTF-420).
     */
    private function reply(string $chatId, string $key): void
    {
        $this->client->sendMessage($chatId, (string) __($key));
    }
}
