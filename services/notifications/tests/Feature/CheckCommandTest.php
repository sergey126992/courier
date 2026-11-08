<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\TelegramApiException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Самопроверка конфигурации при старте (FR-OPS-209)
|--------------------------------------------------------------------------
|
| Команда вызывается в entrypoint контейнера: некорректная конфигурация
| останавливает процесс с понятной ошибкой без персональных данных.
|
*/

it('проходит самопроверку при корректной конфигурации', function (): void {
    $this->artisan('notifications:check')
        ->assertExitCode(0);
});

it('останавливает процесс, когда каталог схем не читается', function (): void {
    config(['notifications.contracts.path' => base_path('contracts/missing')]);

    $this->artisan('notifications:check')
        ->expectsOutputToContain('notifications.contracts.path')
        ->assertExitCode(1);
});

it('сообщает о незаполненном имени потока', function (): void {
    config(['notifications.transport.streams.orders' => '']);

    $this->artisan('notifications:check')
        ->expectsOutputToContain('notifications.transport.streams.orders')
        ->assertExitCode(1);
});

it('останавливает процесс, если Telegram включён без токена', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => '',
        'notifications.telegram.bot_username' => 'courier_bot',
    ]);

    $this->artisan('notifications:check')
        ->expectsOutputToContain('notifications.telegram.bot_token')
        ->assertExitCode(1);
});

it('останавливает процесс, если Telegram включён без имени бота', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'bot-token-value',
        'notifications.telegram.bot_username' => '',
    ]);

    $this->artisan('notifications:check')
        ->expectsOutputToContain('notifications.telegram.bot_username')
        ->assertExitCode(1);
});

it('проходит самопроверку с включённым Telegram и заполненными ключами', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'bot-token-value',
        'notifications.telegram.bot_username' => 'courier_bot',
    ]);

    $this->artisan('notifications:check')->assertExitCode(0);
});

it('не выводит значения токена Telegram в сообщениях', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'super-secret-token',
        'notifications.telegram.bot_username' => 'courier_bot',
    ]);

    $this->artisan('notifications:check', ['--json' => true])
        ->expectsOutputToContain('"status":"ok"')
        ->doesntExpectOutputToContain('super-secret-token')
        ->assertExitCode(0);
});

it('выводит результат самопроверки в JSON', function (): void {
    $this->artisan('notifications:check', ['--json' => true])
        ->expectsOutputToContain('"status":"ok"')
        ->assertExitCode(0);
});

it('вызывает getMe только по явному флагу --telegram', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'bot-token-value',
        'notifications.telegram.bot_username' => 'courier_bot',
    ]);

    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    // Без флага Telegram не вызывается: внешний канал не в критичном пути
    // самопроверки и /health (FR-OPS-414, FR-OPS-415).
    $this->artisan('notifications:check')->assertExitCode(0);

    expect($client->meCalls)->toBe(0);

    $this->artisan('notifications:check', ['--telegram' => true])
        ->expectsOutputToContain('проверка пройдена')
        ->doesntExpectOutputToContain('bot-token-value')
        ->assertExitCode(0);

    expect($client->meCalls)->toBe(1);
});

it('не выполняет getMe без токена бота', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push'],
        'notifications.telegram.bot_token' => '',
        'notifications.telegram.bot_username' => '',
    ]);

    $client = new FakeTelegramBotClient;
    app()->instance(TelegramBotClient::class, $client);

    $this->artisan('notifications:check', ['--telegram' => true, '--json' => true])
        ->expectsOutputToContain('"telegram":"skipped"')
        ->assertExitCode(0);

    expect($client->meCalls)->toBe(0);
});

it('сообщает об отказе getMe без раскрытия токена', function (): void {
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.telegram.bot_token' => 'super-secret-token',
        'notifications.telegram.bot_username' => 'courier_bot',
    ]);

    $client = new FakeTelegramBotClient;
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 401, 'Unauthorized');
    app()->instance(TelegramBotClient::class, $client);

    $this->artisan('notifications:check', ['--telegram' => true])
        ->expectsOutputToContain('--telegram')
        ->doesntExpectOutputToContain('super-secret-token')
        ->assertExitCode(1);
});

it('останавливает процесс, когда база сервиса недоступна', function (): void {
    config([
        'database.connections.pgsql.host' => 'database.invalid',
        'database.connections.pgsql.connect_timeout' => 1,
    ]);
    DB::purge('pgsql');

    $this->artisan('notifications:check')
        ->expectsOutputToContain('База сервиса недоступна')
        ->assertExitCode(1);
});

it('останавливает процесс на некорректной конфигурации', function (
    string $key,
    mixed $value,
    string $needle,
    array $extra = [],
): void {
    config(array_merge([$key => $value], $extra));

    $this->artisan('notifications:check')
        ->expectsOutputToContain($needle)
        ->assertExitCode(1);
})->with([
    'транспорт событий' => ['notifications.transport.driver', '', 'notifications.transport.driver'],
    'consumer group' => ['notifications.transport.consumer_group', '', 'notifications.transport.consumer_group'],
    'размер пачки' => ['notifications.transport.batch_size', 0, 'Размер пачки'],
    'сервисный токен' => ['notifications.api.token', '', 'notifications.api.token'],
    'лимит сообщения Telegram' => [
        'notifications.telegram.message_limit',
        0,
        'notifications.telegram.message_limit',
        [
            'notifications.enabled_channels' => ['email', 'push', 'telegram'],
            'notifications.telegram.bot_token' => 'bot-token-value',
            'notifications.telegram.bot_username' => 'courier_bot',
        ],
    ],
    'число попыток отправки' => ['notifications.sender.max_attempts', 0, 'Число попыток'],
    'таймаут отправки' => ['notifications.sender.timeout_seconds', 0, 'Таймаут отправки'],
    'пауза повтора' => ['notifications.sender.retry_after_seconds', 0, 'Пауза повтора'],
    'срок хранения' => ['notifications.retention.days', 0, 'notifications.retention.days'],
    'расписание очистки' => ['notifications.retention.purge_cron', '', 'notifications.retention.purge_cron'],
    'ряд задержек повтора' => ['notifications.sender.backoff_seconds', [], 'notifications.sender.backoff_seconds'],
    'порог лага' => ['notifications.health.lag_threshold_seconds', -1, 'Порог лага'],
    'порог необработанных' => ['notifications.health.pending_threshold', -1, 'Порог числа необработанных'],
]);
