<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\InvalidTrackingCode;
use App\Domain\Order\TrackingCode;

it('генерирует код с префиксом и случайной частью', function (): void {
    $code = TrackingCode::generate();

    expect($code->value())->toStartWith(TrackingCode::PREFIX)
        ->and($code->value())->toMatch('/^trk_[a-z0-9]{8,32}$/')
        ->and(strlen($code->value()))->toBe(20);
});

it('генерирует разные коды', function (): void {
    $codes = [];

    for ($i = 0; $i < 20; $i++) {
        $codes[] = TrackingCode::generate()->value();
    }

    expect(array_unique($codes))->toHaveCount(20);
});

it('принимает корректный код', function (string $value): void {
    expect(TrackingCode::fromString($value)->value())->toBe($value);
})->with([
    'из ЧТЗ' => ['trk_9f3a1c5e'],
    'длинный' => ['trk_9f3a1c5e7b2d4f60'],
]);

it('отклоняет код неверного формата', function (string $value): void {
    expect(static fn () => TrackingCode::fromString($value))
        ->toThrow(InvalidTrackingCode::class);
})->with([
    'без префикса' => ['9f3a1c5e'],
    'верхний регистр' => ['TRK_9f3a1c5e'],
    'случайная часть короче восьми' => ['trk_9f3a1c5'],
    'случайная часть длиннее 32' => ['trk_'.str_repeat('a', 33)],
    'посторонний символ' => ['trk_9f3a1c5e!'],
    'пустой' => [''],
]);

it('сравнивает коды по значению', function (): void {
    $code = TrackingCode::fromString('trk_9f3a1c5e');

    expect($code->equals(TrackingCode::fromString('trk_9f3a1c5e')))->toBeTrue()
        ->and($code->equals(TrackingCode::fromString('trk_00000000')))->toBeFalse()
        ->and((string) $code)->toBe('trk_9f3a1c5e');
});
