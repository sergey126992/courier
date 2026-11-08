<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\HttpTelegramBotClient;
use App\Services\Notifications\Channels\TelegramBotClientFactory;

/*
|--------------------------------------------------------------------------
| Выбор клиента Bot API по конфигурации (FR-NTF-433)
|--------------------------------------------------------------------------
|
| В рабочем окружении — HTTP-адаптер, для тестов и демонстрации — фейк.
| Неизвестное значение останавливает отправку ошибкой конфигурации.
|
*/

it('отдаёт HTTP-адаптер по умолчанию', function (): void {
    config(['notifications.telegram.client' => '']);

    expect((new TelegramBotClientFactory)->make())->toBeInstanceOf(HttpTelegramBotClient::class);
});

it('отдаёт фейковый клиент по конфигурации', function (): void {
    config(['notifications.telegram.client' => 'fake']);

    expect((new TelegramBotClientFactory)->make())->toBeInstanceOf(FakeTelegramBotClient::class);
});

it('останавливает выбор клиента при неизвестном значении', function (): void {
    config(['notifications.telegram.client' => 'carrier-pigeon']);

    expect(fn (): object => (new TelegramBotClientFactory)->make())
        ->toThrow(InvalidArgumentException::class);
});
