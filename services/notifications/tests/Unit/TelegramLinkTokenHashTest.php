<?php

declare(strict_types=1);

use App\Domain\Notifications\TelegramLinkTokenHash;

/*
|--------------------------------------------------------------------------
| Хеширование токена привязки Telegram (ЧТЗ 9.1 п. 11, FR-NTF-413)
|--------------------------------------------------------------------------
|
| В базе хранится только SHA-256 значения, а сравнение выполняется
| постоянным по времени способом: по времени ответа нельзя подобрать токен.
|
*/

it('хеширует токен привязки алгоритмом SHA-256', function (): void {
    $plain = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    $hash = TelegramLinkTokenHash::hash($plain);

    expect(TelegramLinkTokenHash::ALGORITHM)->toBe('sha256')
        ->and($hash)->toBe(hash('sha256', $plain))
        ->and($hash)->toHaveLength(64)
        ->and($hash)->not->toContain($plain);
});

it('совпадает только с хешем того же значения', function (): void {
    $plain = 'token-value-1';
    $hash = TelegramLinkTokenHash::hash($plain);

    expect(TelegramLinkTokenHash::matches($plain, $hash))->toBeTrue()
        ->and(TelegramLinkTokenHash::matches('token-value-2', $hash))->toBeFalse()
        ->and(TelegramLinkTokenHash::matches('', $hash))->toBeFalse()
        ->and(TelegramLinkTokenHash::matches($plain, ''))->toBeFalse()
        ->and(TelegramLinkTokenHash::matches($plain, strtoupper($hash)))->toBeFalse();
});

it('не зависит от длины сравниваемого значения', function (): void {
    $hash = TelegramLinkTokenHash::hash('abc');

    expect(TelegramLinkTokenHash::matches(str_repeat('a', 512), $hash))->toBeFalse();
});
