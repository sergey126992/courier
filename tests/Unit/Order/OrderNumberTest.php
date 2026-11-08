<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\InvalidOrderNumber;
use App\Domain\Order\OrderNumber;

it('разбирает корректный номер', function (): void {
    $number = OrderNumber::fromString('CRG-2026-000123');

    expect($number->value())->toBe('CRG-2026-000123')
        ->and($number->year())->toBe(2026)
        ->and($number->sequence())->toBe(123);
});

it('строит номер из последовательности базы', function (): void {
    expect(OrderNumber::fromSequence(123, 2026)->value())->toBe('CRG-2026-000123')
        ->and(OrderNumber::fromSequence(1, 2026)->value())->toBe('CRG-2026-000001')
        ->and(OrderNumber::fromSequence(999999, 2026)->value())->toBe('CRG-2026-999999');
});

it('отклоняет номер неверного формата', function (string $value): void {
    expect(static fn () => OrderNumber::fromString($value))
        ->toThrow(InvalidOrderNumber::class);
})->with([
    'пустой' => [''],
    'без префикса' => ['2026-000123'],
    'нижний регистр' => ['crg-2026-000123'],
    'двузначный год' => ['CRG-26-000123'],
    'пять цифр последовательности' => ['CRG-2026-00123'],
    'семь цифр последовательности' => ['CRG-2026-0001234'],
    'пробел в конце' => ['CRG-2026-000123 '],
]);

it('отклоняет последовательность вне диапазона', function (int $sequence): void {
    expect(static fn () => OrderNumber::fromSequence($sequence, 2026))
        ->toThrow(InvalidOrderNumber::class);
})->with([
    'ноль' => [0],
    'отрицательная' => [-5],
    'больше шести цифр' => [1000000],
]);

it('отклоняет некорректный год', function (int $year): void {
    expect(static fn () => OrderNumber::fromSequence(1, $year))
        ->toThrow(InvalidOrderNumber::class);
})->with([
    'трёхзначный' => [999],
    'пятизначный' => [10000],
]);

it('сравнивает номера по значению', function (): void {
    $number = OrderNumber::fromSequence(7, 2026);

    expect($number->equals(OrderNumber::fromString('CRG-2026-000007')))->toBeTrue()
        ->and($number->equals(OrderNumber::fromSequence(8, 2026)))->toBeFalse()
        ->and((string) $number)->toBe('CRG-2026-000007');
});
