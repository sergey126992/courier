<?php

declare(strict_types=1);

use App\Models\TelegramLinkToken;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\TelegramApiException;
use App\Services\Notifications\Telegram\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Команды бота Telegram (ЧТЗ 8.6, FR-NTF-442)
|--------------------------------------------------------------------------
|
| Проверяются коды возврата 0/1/2, ключи `--once`, `--limit`, `--json`,
| регистрация и удаление вебхука, очистка токенов и отсутствие секретов,
| токена бота и `chat_id` в выводе. Сети и сна нет: клиент подменён фейком.
|
*/

uses(RefreshDatabase::class);

const COMMAND_TEST_CHAT = 660500400;
const COMMAND_TEST_USER = 51;
const COMMAND_TEST_TOKEN = 'test-bot-token';
const COMMAND_TEST_SECRET = 'command-webhook-secret';

/**
 * Фейковый клиент Telegram, подставленный в контейнер.
 */
function telegramCommandClient(): FakeTelegramBotClient
{
    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    return $client;
}

/**
 * Апдейт с командой `/start <token>`.
 *
 * @return array<string, mixed>
 */
function telegramCommandUpdate(int $updateId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'chat' => ['id' => COMMAND_TEST_CHAT, 'type' => 'private'],
            'text' => $text,
        ],
    ];
}

/**
 * Открытое значение выпущенного токена привязки.
 */
function telegramCommandToken(int $userId = COMMAND_TEST_USER): string
{
    $start = app(TelegramLinkService::class)->start($userId);
    $query = explode('?', $start->deepLink, 2)[1] ?? '';
    parse_str($query, $params);
    $token = $params['start'] ?? null;

    return is_string($token) ? $token : '';
}

beforeEach(function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_username' => 'courier_test_bot',
        'notifications.telegram.bot_token' => COMMAND_TEST_TOKEN,
        'notifications.telegram.link_token_ttl_seconds' => 900,
        'notifications.telegram.link_token_retention_days' => 7,
        'notifications.telegram.poll_batch_size' => 2,
        'notifications.telegram.poll_timeout_seconds' => 0,
        'notifications.telegram.poll_interval_seconds' => 1,
        'notifications.telegram.webhook_url' => 'https://notifications.test/api/v1/telegram/webhook',
        'notifications.telegram.webhook_secret' => COMMAND_TEST_SECRET,
        'notifications.telegram.updates_mode' => 'polling',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('обрабатывает пачку апдейтов ключом --once и выводит JSON', function (): void {
    $client = telegramCommandClient();
    $token = telegramCommandToken();

    $client->queue([
        telegramCommandUpdate(700, '/start '.$token),
        telegramCommandUpdate(701, 'привет'),
    ]);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true, '--json' => true]))->toBe(0);

    $output = trim(Artisan::output());
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('ok')
        ->and($payload['processed'])->toBe(2)
        ->and($payload['linked'])->toBe(1)
        ->and($payload['ignored'])->toBe(1)
        ->and($payload['errors'])->toBe(0);

    expect($output)->not->toContain(COMMAND_TEST_TOKEN)
        ->not->toContain((string) COMMAND_TEST_CHAT)
        ->not->toContain($token);

    $this->assertDatabaseHas('channel_bindings', [
        'user_id' => COMMAND_TEST_USER,
        'channel' => 'telegram',
        'revoked_at' => null,
    ]);
});

it('ограничивает пачку ключом --limit', function (): void {
    $client = telegramCommandClient();
    $client->queue([
        telegramCommandUpdate(710, 'первый'),
        telegramCommandUpdate(711, 'второй'),
        telegramCommandUpdate(712, 'третий'),
    ]);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true, '--limit' => '1']))->toBe(0);

    expect(trim(Artisan::output()))->toContain('обработано 1');
});

it('возвращает 2 без токена бота', function (): void {
    telegramCommandClient();
    config(['notifications.telegram.bot_token' => '']);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true]))->toBe(2)
        ->and(Artisan::output())->not->toContain(COMMAND_TEST_TOKEN);
});

it('не запускает long polling в режиме вебхука', function (): void {
    telegramCommandClient();
    config(['notifications.telegram.updates_mode' => 'webhook']);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true]))->toBe(2)
        ->and(Artisan::output())->toContain('updates_mode')
        ->and(Artisan::output())->not->toContain(COMMAND_TEST_TOKEN);
});

it('возвращает 2 на некорректный ключ --limit', function (): void {
    telegramCommandClient();

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true, '--limit' => 'abc']))->toBe(2);
});

it('возвращает 1, когда Telegram отвечает ошибкой', function (): void {
    $client = telegramCommandClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 500);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true, '--json' => true]))->toBe(1);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['errors'])->toBe(1);
});

it('регистрирует и удаляет вебхук', function (): void {
    $client = telegramCommandClient();

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true, '--json' => true]))->toBe(0);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('ok')
        ->and($payload['action'])->toBe('set');

    expect($client->webhooks)->toHaveCount(1)
        ->and($client->webhooks[0]['url'])->toBe('https://notifications.test/api/v1/telegram/webhook')
        ->and($client->webhooks[0]['secret'])->toBe(COMMAND_TEST_SECRET);

    expect(Artisan::call('notifications:telegram:webhook', ['--delete' => true, '--json' => true]))->toBe(0);

    expect($client->deleted)->toBe(1);

    $output = trim(Artisan::output());
    expect($output)->not->toContain(COMMAND_TEST_SECRET)
        ->not->toContain(COMMAND_TEST_TOKEN);
});

it('возвращает 2 без ключа действия и без адреса вебхука', function (): void {
    telegramCommandClient();

    expect(Artisan::call('notifications:telegram:webhook'))->toBe(2);

    config(['notifications.telegram.webhook_url' => '']);

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true]))->toBe(2);
});

it('возвращает 1, когда Telegram отказал в регистрации вебхука', function (): void {
    $client = telegramCommandClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 401);

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true]))->toBe(1);
});

it('удаляет использованные и истёкшие токены привязки', function (): void {
    $used = TelegramLinkToken::factory()->create([
        'user_id' => COMMAND_TEST_USER,
        'used_at' => Carbon::now('UTC')->subDays(10),
        'expires_at' => Carbon::now('UTC')->subDays(10),
    ]);
    $expired = TelegramLinkToken::factory()->create([
        'user_id' => COMMAND_TEST_USER,
        'used_at' => null,
        'expires_at' => Carbon::now('UTC')->subDays(9),
    ]);
    $fresh = TelegramLinkToken::factory()->create([
        'user_id' => COMMAND_TEST_USER,
        'used_at' => null,
        'expires_at' => Carbon::now('UTC')->addDay(),
    ]);

    expect(Artisan::call('notifications:telegram:purge', ['--days' => 1, '--json' => true]))->toBe(0);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('ok')
        ->and($payload['days'])->toBe(1)
        ->and($payload['deleted'])->toBe(2);

    $this->assertDatabaseMissing('telegram_link_tokens', ['id' => $used->id]);
    $this->assertDatabaseMissing('telegram_link_tokens', ['id' => $expired->id]);
    $this->assertDatabaseHas('telegram_link_tokens', ['id' => $fresh->id]);
});

it('берёт срок хранения токенов из конфигурации и возвращает 2 на некорректный ключ', function (): void {
    expect(Artisan::call('notifications:telegram:purge', ['--json' => true]))->toBe(0);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['days'])->toBe(7);

    expect(Artisan::call('notifications:telegram:purge', ['--days' => 'abc']))->toBe(2);
});

it('выводит ошибку конфигурации опроса в JSON без токена бота', function (): void {
    telegramCommandClient();
    config(['notifications.telegram.bot_token' => '']);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true, '--json' => true]))->toBe(2);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('error')
        ->and($payload['error'])->toContain('bot_token')
        ->and(trim(Artisan::output()))->not->toContain(COMMAND_TEST_TOKEN);
});

it('возвращает 1, когда обработчик апдейта упал на отправке ответа', function (): void {
    $client = new class([telegramCommandUpdate(720, '/start')]) implements TelegramBotClient
    {
        /**
         * @param list<array<string, mixed>> $updates
         */
        public function __construct(private array $updates) {}

        public function getUpdates(?int $offset, int $limit, int $timeoutSeconds): array
        {
            return array_splice($this->updates, 0, max(1, $limit));
        }

        public function sendMessage(string $chatId, string $text): void
        {
            throw new RuntimeException('Отправка ответа недоступна.');
        }

        public function getMe(): array
        {
            return ['id' => 1, 'is_bot' => true];
        }

        public function setWebhook(string $url, string $secret): bool
        {
            return true;
        }

        public function deleteWebhook(): bool
        {
            return true;
        }
    };

    app()->instance(TelegramBotClient::class, $client);

    expect(Artisan::call('notifications:telegram:poll', ['--once' => true, '--json' => true]))->toBe(1);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['errors'])->toBe(1)
        ->and($payload['processed'])->toBe(0)
        ->and($payload['status'])->toBe('ok');
});

it('возвращает 2 без секрета и без токена бота при регистрации вебхука', function (): void {
    telegramCommandClient();
    config(['notifications.telegram.webhook_secret' => '']);

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true]))->toBe(2)
        ->and(Artisan::output())->toContain('webhook_secret');

    config([
        'notifications.telegram.webhook_secret' => COMMAND_TEST_SECRET,
        'notifications.telegram.bot_token' => '',
    ]);

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true]))->toBe(2)
        ->and(Artisan::output())->toContain('bot_token');
});

it('выводит отказ вебхука в JSON с ключом error', function (): void {
    $client = telegramCommandClient();
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 401);

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true, '--json' => true]))->toBe(1);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('error')
        ->and($payload['action'])->toBe('set')
        ->and($payload['error'])->toBeString()
        ->and(trim(Artisan::output()))->not->toContain(COMMAND_TEST_SECRET);
});

it('пишет действие вебхука в обычном выводе', function (): void {
    telegramCommandClient();

    expect(Artisan::call('notifications:telegram:webhook', ['--set' => true]))->toBe(0)
        ->and(trim(Artisan::output()))->toContain('зарегистрирован');

    expect(Artisan::call('notifications:telegram:webhook', ['--delete' => true]))->toBe(0)
        ->and(trim(Artisan::output()))->toContain('удалён');
});

it('отвечает JSON-ошибкой на неверные ключи вебхука', function (): void {
    telegramCommandClient();

    expect(Artisan::call('notifications:telegram:webhook', [
        '--set' => true,
        '--delete' => true,
        '--json' => true,
    ]))->toBe(2);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('error')
        ->and($payload['error'])->toContain('--set');
});

it('пишет счётчик очистки токенов в обычном выводе', function (): void {
    TelegramLinkToken::factory()->create([
        'user_id' => COMMAND_TEST_USER,
        'used_at' => Carbon::now('UTC')->subDays(10),
        'expires_at' => Carbon::now('UTC')->subDays(10),
    ]);

    expect(Artisan::call('notifications:telegram:purge', ['--days' => 1]))->toBe(0)
        ->and(trim(Artisan::output()))->toContain('удалено токенов');
});

it('отвечает JSON-ошибкой на некорректный срок очистки', function (): void {
    expect(Artisan::call('notifications:telegram:purge', ['--days' => 'abc', '--json' => true]))->toBe(2);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('error')
        ->and($payload['error'])->toContain('положительным');
});

it('возвращает 2, когда очистка токенов завершилась ошибкой базы', function (): void {
    DB::statement('drop table telegram_link_tokens');

    expect(Artisan::call('notifications:telegram:purge', ['--json' => true]))->toBe(2);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('error')
        ->and($payload['error'])->toBeString();
});
