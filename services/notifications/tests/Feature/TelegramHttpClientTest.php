<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\HttpTelegramBotClient;
use App\Services\Notifications\Channels\TelegramApiException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| HTTP-адаптер Bot API Telegram (ЧТЗ 9.5, FR-NTF-433, FR-NTF-443)
|--------------------------------------------------------------------------
|
| Сети нет: ответы Bot API подменяются `Http::fake()`. Токен бота входит
| в адрес метода, но в тексты исключений не попадает.
|
*/

const HTTP_TEST_TOKEN = 'http-test-bot-token';
const HTTP_TEST_API = 'https://api.telegram.test';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('получает апдейты через getUpdates с параметрами из вызова', function (): void {
    Http::fake([
        HTTP_TEST_API.'/*' => Http::response([
            'ok' => true,
            'result' => [
                ['update_id' => 1, 'message' => ['text' => '/start']],
                'не апдейт',
            ],
        ]),
    ]);

    $client = new HttpTelegramBotClient(HTTP_TEST_TOKEN, HTTP_TEST_API, 5, 1);

    $updates = $client->getUpdates(10, 3, 1);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['update_id'])->toBe(1);

    Http::assertSent(static function ($request): bool {
        return $request->url() === HTTP_TEST_API.'/bot'.HTTP_TEST_TOKEN.'/getUpdates'
            && $request['limit'] === 3
            && $request['offset'] === 10
            && $request['timeout'] === 1;
    });
});

it('отправляет сообщение через sendMessage', function (): void {
    Http::fake([HTTP_TEST_API.'/*' => Http::response(['ok' => true, 'result' => []])]);

    $client = new HttpTelegramBotClient(HTTP_TEST_TOKEN, HTTP_TEST_API, 5, 1);
    $client->sendMessage('777', 'Текст сообщения');

    Http::assertSent(static function ($request): bool {
        return $request->url() === HTTP_TEST_API.'/bot'.HTTP_TEST_TOKEN.'/sendMessage'
            && $request['chat_id'] === '777'
            && $request['text'] === 'Текст сообщения';
    });
});

it('регистрирует и удаляет вебхук с секретом', function (): void {
    Http::fake([HTTP_TEST_API.'/*' => Http::response(['ok' => true, 'result' => true])]);

    $client = new HttpTelegramBotClient(HTTP_TEST_TOKEN, HTTP_TEST_API, 5, 1);

    expect($client->setWebhook('https://notifications.test/api/v1/telegram/webhook', 'secret-value'))->toBeTrue()
        ->and($client->deleteWebhook())->toBeTrue();

    Http::assertSent(static function ($request): bool {
        return str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === 'secret-value';
    });

    Http::assertSent(static function ($request): bool {
        return str_ends_with($request->url(), '/deleteWebhook');
    });
});

it('сообщает об ошибке без токена бота в тексте', function (): void {
    Http::fake([HTTP_TEST_API.'/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    $client = new HttpTelegramBotClient(HTTP_TEST_TOKEN, HTTP_TEST_API, 5, 1);

    try {
        $client->sendMessage('777', 'Текст');
        $this->fail('Ожидалась ошибка Bot API Telegram.');
    } catch (TelegramApiException $exception) {
        expect($exception->status())->toBe(401)
            ->and($exception->getMessage())->not->toContain(HTTP_TEST_TOKEN)
            ->not->toContain('777');
    }
});
