<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationChannel;
use App\Logging\TechnicalLogger;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Регистрация и удаление вебхука Telegram (FR-NTF-442).
 *
 * Адрес вебхука и секрет приходят из конфигурации окружения: у демо-стенда
 * и CI свои значения, в репозитории секретов нет (FR-OPS-212). Ключи
 * `--set` и `--delete` взаимоисключающие.
 *
 * Коды возврата: 0 — выполнено; 1 — Telegram отказал; 2 — ошибка
 * конфигурации или ключа. В выводе нет токена бота и секрета вебхука
 * (FR-OPS-411).
 */
final class TelegramWebhookCommand extends Command
{
    /**
     * Действие выполнено.
     */
    public const EXIT_OK = 0;

    /**
     * Telegram отказал в регистрации или удалении.
     */
    public const EXIT_FAILED = 1;

    /**
     * Ошибка конфигурации или ключа.
     */
    public const EXIT_INVALID = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:telegram:webhook
        {--set : Зарегистрировать вебхук}
        {--delete : Удалить вебхук}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Регистрирует или удаляет вебхук бота Telegram';

    public function handle(TelegramBotClient $client, TechnicalLogger $logger): int
    {
        $set = (bool) $this->option('set');
        $remove = (bool) $this->option('delete');

        if ($set === $remove) {
            return $this->reportInvalid('Укажите ровно один ключ: --set или --delete.');
        }

        if ($set) {
            $url = trim((string) config('notifications.telegram.webhook_url'));
            $secret = (string) config('notifications.telegram.webhook_secret');

            if ($url === '') {
                return $this->reportInvalid('Не задан публичный адрес вебхука (notifications.telegram.webhook_url).');
            }

            if ($secret === '') {
                return $this->reportInvalid('Не задан секрет вебхука (notifications.telegram.webhook_secret).');
            }

            if (trim((string) config('notifications.telegram.bot_token')) === '') {
                return $this->reportInvalid('Не задан токен бота Telegram (notifications.telegram.bot_token).');
            }
        }

        try {
            $result = $set
                ? $client->setWebhook(
                    trim((string) config('notifications.telegram.webhook_url')),
                    (string) config('notifications.telegram.webhook_secret'),
                )
                : $client->deleteWebhook();
        } catch (Throwable) {
            $logger->error('Управление вебхуком Telegram завершилось ошибкой.', [
                'channel' => NotificationChannel::Telegram->value,
                'status' => 'error',
            ]);

            $this->render('error', $set ? 'set' : 'delete', 'Telegram отказал в выполнении запроса.');

            return self::EXIT_FAILED;
        }

        $this->render($result ? 'ok' : 'error', $set ? 'set' : 'delete', null);

        return $result ? self::EXIT_OK : self::EXIT_FAILED;
    }

    /**
     * Вывод результата: действие и статус, без адреса и секрета.
     */
    private function render(string $status, string $action, ?string $error): void
    {
        if ((bool) $this->option('json')) {
            $payload = ['command' => $this->getName(), 'status' => $status, 'action' => $action];

            if ($error !== null) {
                $payload['error'] = $error;
            }

            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($error !== null) {
            $this->error($error);

            return;
        }

        $this->info(sprintf('%s: вебхук %s', $this->getName(), $action === 'set' ? 'зарегистрирован' : 'удалён'));
    }

    private function reportInvalid(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['command' => $this->getName(), 'status' => 'error', 'error' => $message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_INVALID;
    }
}
