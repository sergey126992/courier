<?php

declare(strict_types=1);

use App\Domain\Notifications\FallbackChain;

/*
|--------------------------------------------------------------------------
| Глубина цепочки замещения канала (FR-NTF-479, ЧТЗ 9.1 №12)
|--------------------------------------------------------------------------
|
| Домен считает глубину только по ссылкам `fallback_from_notification_id`:
| базы, часов и текстов ошибок здесь нет. Предел проверяется отдельно.
|
*/

it('считает глубину строки без ссылки нулевой', function (): void {
    expect(FallbackChain::depth(10, [10 => null]))->toBe(0)
        ->and(FallbackChain::depth(10, []))->toBe(0);
});

it('считает глубину по ссылкам fallback_from_notification_id', function (): void {
    $links = [30 => 20, 20 => 10, 10 => null];

    expect(FallbackChain::depth(30, $links))->toBe(2)
        ->and(FallbackChain::depth(20, $links))->toBe(1)
        ->and(FallbackChain::depth(10, $links))->toBe(0);
});

it('не зацикливается на испорченных ссылках', function (): void {
    $links = [10 => 20, 20 => 10];

    expect(FallbackChain::depth(10, $links))->toBe(2);
});

it('не помещает новую строку сверх предела глубины', function (): void {
    expect(FallbackChain::hasRoom(0, 3))->toBeTrue()
        ->and(FallbackChain::hasRoom(1, 3))->toBeTrue()
        ->and(FallbackChain::hasRoom(2, 3))->toBeFalse()
        ->and(FallbackChain::hasRoom(0, 1))->toBeFalse();
});
