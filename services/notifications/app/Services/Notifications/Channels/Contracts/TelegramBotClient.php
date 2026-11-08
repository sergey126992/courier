<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Contracts;

/**
 * Контракт Bot API Telegram (FR-NTF-433, FR-NTF-442).
 *
 * Канал и обработчик апдейтов зависят только от контракта: HTTP-адаптер
 * знает про сеть, таймауты и токен бота, фейк — про тесты. Обе реализации
 * покрывают и приём апдейтов (`getUpdates`), и отправку сообщения
 * (`sendMessage`): `/start` подтверждает привязку, а канал M12 отправляет
 * уведомления тем же контрактом.
 *
 * Токен бота, `chat_id` и тексты сообщений в сообщения об ошибках
 * не попадают (FR-OPS-411, FR-NTF-419).
 */
interface TelegramBotClient
{
    /**
     * Апдейты бота, полученные long polling.
     *
     * @param int|null $offset идентификатор первого нужного апдейта (`update_id + 1`)
     * @param int $limit размер пачки апдейтов
     * @param int $timeoutSeconds таймаут long polling в секундах
     * @return list<array<string, mixed>> апдейты в порядке возрастания `update_id`
     */
    public function getUpdates(?int $offset, int $limit, int $timeoutSeconds): array;

    /**
     * Отправляет текстовое сообщение в чат.
     */
    public function sendMessage(string $chatId, string $text): void;

    /**
     * Сведения о боте методом `getMe` (FR-OPS-415).
     *
     * Вызывается только диагностикой `notifications:check --telegram`
     * по явному флагу: `/health` к Bot API не обращается (FR-OPS-414).
     * Токен бота в результат не попадает.
     *
     * @return array<string, mixed> поля ответа `getMe`
     */
    public function getMe(): array;

    /**
     * Регистрирует вебхук с секретом или подтверждает регистрацию.
     */
    public function setWebhook(string $url, string $secret): bool;

    /**
     * Удаляет зарегистрированный вебхук.
     */
    public function deleteWebhook(): bool;
}
