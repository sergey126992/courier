<?php

declare(strict_types=1);

use App\Domain\Notifications\Exceptions\NotificationInfrastructureException;
use App\Domain\Notifications\Exceptions\TelegramChatNotFound;
use App\Domain\Notifications\Exceptions\TelegramRateLimited;
use App\Domain\Notifications\Exceptions\TelegramRequestRejected;
use App\Domain\Notifications\NotificationErrorCode;
use App\Services\Notifications\Channels\TelegramApiException;
use App\Services\Notifications\Telegram\TelegramResponseMapper;

/*
|--------------------------------------------------------------------------
| Разбор ответов Bot API Telegram (ЧТЗ 9.1 №7, FR-NTF-436…439)
|--------------------------------------------------------------------------
|
| Таблица «ответ Telegram → код → повтор/fallback» проверяется без сети:
| исключение транспорта конструируется напрямую. Значение `retry_after`
| берётся из ответа, а при отсутствии — из конфигурации.
|
*/

beforeEach(function (): void {
    config(['notifications.telegram.retry_after_default_seconds' => 30]);
});

it('берёт retry_after из ответа 429', function (): void {
    $failure = new TelegramApiException(
        'API Telegram ответил ошибкой.',
        429,
        'Too Many Requests: retry after 17',
        17,
    );

    $mapped = (new TelegramResponseMapper)->map($failure);

    expect($mapped)->toBeInstanceOf(TelegramRateLimited::class);

    if (! $mapped instanceof TelegramRateLimited) {
        return;
    }

    expect($mapped->errorCode())->toBe(NotificationErrorCode::TELEGRAM_RATE_LIMITED)
        ->and($mapped->retryAfterSeconds())->toBe(17);
});

it('подставляет retry_after по умолчанию для 429 без значения', function (): void {
    $mapped = (new TelegramResponseMapper)->map(new TelegramApiException('API Telegram ответил ошибкой.', 429));

    expect($mapped)->toBeInstanceOf(TelegramRateLimited::class);

    if (! $mapped instanceof TelegramRateLimited) {
        return;
    }

    expect($mapped->retryAfterSeconds())->toBe(30);
});

it('считает 400 chat not found и 403 blocked недоступным чатом', function (string $description, int $status): void {
    $mapped = (new TelegramResponseMapper)->map(
        new TelegramApiException('API Telegram ответил ошибкой.', $status, $description),
    );

    expect($mapped)->toBeInstanceOf(TelegramChatNotFound::class)
        ->and($mapped->errorCode())->toBe(NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND);
})->with([
    '400 chat not found' => ['Bad Request: chat not found', 400],
    '403 blocked' => ['Forbidden: bot was blocked by the user', 403],
]);

it('относит 5xx, сеть, таймаут и неверный токен к инфраструктуре', function (int $status, string $description): void {
    $mapped = (new TelegramResponseMapper)->map(
        new TelegramApiException('API Telegram ответил ошибкой.', $status, $description),
    );

    expect($mapped)->toBeInstanceOf(NotificationInfrastructureException::class)
        ->and($mapped->errorCode())->toBe(NotificationErrorCode::TELEGRAM_API_FAILED);
})->with([
    'сеть' => [0, ''],
    '5xx' => [502, 'Bad Gateway'],
    '401 токен' => [401, 'Unauthorized'],
    '403 токен' => [403, 'Forbidden: bot is not a member'],
]);

it('относит прочие 4xx к бизнес-отказу без повтора', function (): void {
    $mapped = (new TelegramResponseMapper)->map(
        new TelegramApiException('API Telegram ответил ошибкой.', 400, 'Bad Request: message text is empty'),
    );

    expect($mapped)->toBeInstanceOf(TelegramRequestRejected::class)
        ->and($mapped->errorCode())->toBe(NotificationErrorCode::TELEGRAM_REQUEST_REJECTED);
});
