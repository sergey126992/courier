<?php

declare(strict_types=1);

use App\Logging\TechnicalLogFormatter;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Telegram\TelegramLinkService;
use App\Services\Notifications\Telegram\TelegramUpdateResult;
use App\Services\Notifications\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Обработчик /start <token> бота Telegram (ЧТЗ 9.2 п. 9, FR-NTF-415)
|--------------------------------------------------------------------------
|
| Оба режима (long polling и вебхук) используют этот обработчик. Проверяются
| валидный токен, отсутствие токена, мусорный токен и прочие апдейты, а также
| отсутствие `chat_id` и токена в журнале.
|
*/

uses(RefreshDatabase::class);

const UPDATE_TEST_CHAT = 771000333;
const UPDATE_TEST_USER = 21;

/**
 * Фейковый клиент Telegram: сети нет (ЧТЗ 9.5).
 */
function telegramUpdateClient(): FakeTelegramBotClient
{
    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    return $client;
}

/**
 * Апдейт бота с текстовым сообщением.
 *
 * @return array<string, mixed>
 */
function telegramTextUpdate(int $updateId, string $text, int $chatId = UPDATE_TEST_CHAT): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'text' => $text,
        ],
    ];
}

/**
 * Выпускает действующий токен привязки и возвращает открытое значение.
 */
function telegramIssuedToken(int $userId = UPDATE_TEST_USER): string
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
        'notifications.telegram.link_token_ttl_seconds' => 900,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $GLOBALS['updateLogs'] = new ArrayObject;
    Event::listen(MessageLogged::class, static function (MessageLogged $record): void {
        $GLOBALS['updateLogs']->append($record);
    });
});

afterEach(function (): void {
    unset($GLOBALS['updateLogs']);
    Carbon::setTestNow();
});

it('привязывает чат действующим токеном', function (): void {
    $client = telegramUpdateClient();
    $token = telegramIssuedToken();

    $result = app(UpdateHandler::class)->handle(telegramTextUpdate(1, '/start '.$token));

    expect($result)->toBe(TelegramUpdateResult::Linked)
        ->and($client->sent)->toBe([]);

    $this->assertDatabaseHas('channel_bindings', [
        'user_id' => UPDATE_TEST_USER,
        'channel' => 'telegram',
        'revoked_at' => null,
    ]);
});

it('не создаёт привязку без токена и отвечает подсказкой', function (): void {
    $client = telegramUpdateClient();

    $result = app(UpdateHandler::class)->handle(telegramTextUpdate(2, '/start'));

    expect($result)->toBe(TelegramUpdateResult::Prompted)
        ->and($client->sent)->toHaveCount(1)
        ->and($client->sent[0]['chat_id'])->toBe((string) UPDATE_TEST_CHAT);

    $hint = mb_strtolower($client->sent[0]['text']);
    expect($hint)->toContain('настройк');

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('не создаёт привязку по мусорному токену', function (): void {
    $client = telegramUpdateClient();

    $result = app(UpdateHandler::class)->handle(telegramTextUpdate(3, '/start not-a-token'));

    expect($result)->toBe(TelegramUpdateResult::Rejected)
        ->and($client->sent)->toHaveCount(1);

    // Мусорный токен — общая подсказка, а не текст про истёкшую ссылку.
    $hint = mb_strtolower($client->sent[0]['text']);
    expect($hint)->toContain('настройк')
        ->and($hint)->not->toContain('истек');

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('отвечает отдельным текстом на истёкшую ссылку привязки', function (): void {
    $client = telegramUpdateClient();
    $token = telegramIssuedToken();

    // Токен живёт 900 секунд: через 16 минут ссылка истекла (ЧТЗ 5.4 п. 3).
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:16:00', 'UTC'));

    $result = app(UpdateHandler::class)->handle(telegramTextUpdate(11, '/start '.$token));

    expect($result)->toBe(TelegramUpdateResult::Rejected)
        ->and($client->sent)->toHaveCount(1);

    $expired = mb_strtolower($client->sent[0]['text']);
    expect($expired)->toContain('истек')
        ->and($expired)->toContain('настройк');

    // Текст не раскрывает токен и адрес чата.
    expect($expired)->not->toContain($token)
        ->and($expired)->not->toContain((string) UPDATE_TEST_CHAT);

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('игнорирует апдейты без корректного чата', function (): void {
    $client = telegramUpdateClient();
    $handler = app(UpdateHandler::class);

    expect($handler->handle(['update_id' => 12, 'message' => ['text' => '/start', 'chat' => 'oops']]))
        ->toBe(TelegramUpdateResult::Ignored)
        ->and($handler->handle(['update_id' => 13, 'message' => ['text' => '/start', 'chat' => ['id' => true]]]))
        ->toBe(TelegramUpdateResult::Ignored)
        ->and($client->sent)->toBe([]);

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('игнорирует посторонние апдейты', function (): void {
    $client = telegramUpdateClient();
    $handler = app(UpdateHandler::class);

    expect($handler->handle(telegramTextUpdate(4, 'привет')))->toBe(TelegramUpdateResult::Ignored)
        ->and($handler->handle(['update_id' => 5, 'edited_message' => ['text' => '/start']]))->toBe(TelegramUpdateResult::Ignored)
        ->and($handler->handle([]))->toBe(TelegramUpdateResult::Ignored)
        ->and($client->sent)->toBe([]);

    $this->assertDatabaseMissing('channel_bindings', ['channel' => 'telegram']);
});

it('принимает команду с именем бота и не привязывает по чужому токену второго чата', function (): void {
    $client = telegramUpdateClient();
    $first = telegramIssuedToken(31);
    $second = telegramIssuedToken(32);

    expect(app(UpdateHandler::class)->handle(telegramTextUpdate(6, '/start@courier_test_bot '.$first)))
        ->toBe(TelegramUpdateResult::Linked);

    expect(app(UpdateHandler::class)->handle(telegramTextUpdate(7, '/start '.$first)))
        ->toBe(TelegramUpdateResult::Rejected);

    expect(app(UpdateHandler::class)->handle(telegramTextUpdate(8, '/start '.$second)))
        ->toBe(TelegramUpdateResult::Linked);

    expect($client->sent)->toHaveCount(1);
});

it('не пишет chat_id и токен в журнал', function (): void {
    telegramUpdateClient();
    $token = telegramIssuedToken();

    app(UpdateHandler::class)->handle(telegramTextUpdate(9, '/start '.$token));
    app(UpdateHandler::class)->handle(telegramTextUpdate(10, '/start bad-token'));

    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = $GLOBALS['updateLogs'];

    $journal = json_encode(
        collect($logs->getArrayCopy())->map(fn (MessageLogged $record): array => [
            'message' => $record->message,
            'context' => $record->context,
        ])->all(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    expect($journal)->not->toContain((string) UPDATE_TEST_CHAT)
        ->not->toContain($token);

    foreach ($logs->getArrayCopy() as $record) {
        expect(array_diff(array_keys($record->context), TechnicalLogFormatter::ALLOWED_FIELDS))->toBe([]);
    }
});
