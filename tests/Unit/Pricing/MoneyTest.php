<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\CurrencyMismatch;
use App\Domain\Pricing\Money;

it('создаёт сумму в рублях по умолчанию', function (): void {
    $money = Money::fromMinor(14900);

    expect($money->amount())->toBe(14900)
        ->and($money->currency())->toBe('RUB');
});

it('создаёт сумму в заданной валюте фабрикой rub', function (): void {
    $money = Money::rub(9900);

    expect($money->amount())->toBe(9900)
        ->and($money->currency())->toBe('RUB');
});

it('создаёт сумму в произвольной валюте', function (): void {
    expect(Money::fromMinor(500, 'USD')->currency())->toBe('USD');
});

it('складывает суммы', function (): void {
    expect(Money::rub(1000)->plus(Money::rub(250))->amount())->toBe(1250);
});

it('вычитает суммы', function (): void {
    expect(Money::rub(1000)->minus(Money::rub(250))->amount())->toBe(750);
});

it('умножает на целый коэффициент', function (): void {
    expect(Money::rub(2100)->multipliedBy(3)->amount())->toBe(6300);
});

it('округляет результат умножения половину вверх', function (int $amount, float $coefficient, int $expected): void {
    expect(Money::rub($amount)->multipliedBy($coefficient)->amount())->toBe($expected);
})->with([
    'ровно половина вверх' => [5, 0.5, 3],
    'чуть больше половины' => [101, 0.5, 51],
    'копейка на два' => [1, 0.5, 1],
    'полтора раза' => [5, 1.5, 8],
    'процент без потери копеек' => [14900, 0.1, 1490],
    'ставка за километр' => [3500, 4.2, 14700],
]);

it('округляет отрицательные суммы симметрично', function (int $amount, float $coefficient, int $expected): void {
    expect(Money::rub($amount)->multipliedBy($coefficient)->amount())->toBe($expected);
})->with([
    'минус половина' => [-5, 0.5, -3],
    'минус копейка на два' => [-1, 0.5, -1],
    'минус без округления' => [-4, 0.5, -2],
]);

it('сравнивает суммы', function (int $left, int $right, int $expected): void {
    expect(Money::rub($left)->compareTo(Money::rub($right)))->toBe($expected);
})->with([
    'меньше' => [100, 200, -1],
    'равно' => [200, 200, 0],
    'больше' => [300, 200, 1],
]);

it('определяет нулевую сумму', function (): void {
    expect(Money::rub(0)->isZero())->toBeTrue()
        ->and(Money::rub(1)->isZero())->toBeFalse();
});

it('сравнивает суммы целиком вместе с валютой', function (): void {
    expect(Money::rub(100)->equals(Money::rub(100)))->toBeTrue()
        ->and(Money::rub(100)->equals(Money::rub(101)))->toBeFalse()
        ->and(Money::rub(100)->equals(Money::fromMinor(100, 'USD')))->toBeFalse();
});

it('не изменяет исходную сумму при операциях', function (): void {
    $money = Money::rub(1000);

    // Результат операций отбрасываем осознанно: проверяется неизменность исходной суммы.
    (void) $money->plus(Money::rub(500));
    (void) $money->minus(Money::rub(500));
    (void) $money->multipliedBy(2.5);

    expect($money->amount())->toBe(1000);
});

it('запрещает операции с разными валютами', function (Closure $operation): void {
    expect($operation)->toThrow(CurrencyMismatch::class);
})->with([
    'сложение' => [static fn () => Money::rub(100)->plus(Money::fromMinor(100, 'USD'))],
    'вычитание' => [static fn () => Money::rub(100)->minus(Money::fromMinor(100, 'USD'))],
    'сравнение' => [static fn () => Money::rub(100)->compareTo(Money::fromMinor(100, 'USD'))],
]);

it('форматирует сумму для человека', function (Money $money, string $expected): void {
    expect($money->format())->toBe($expected);
})->with([
    'рубли' => [Money::rub(14900), '149,00 ₽'],
    'тысячи рублей' => [Money::rub(123456), '1 234,56 ₽'],
    'отрицательная сумма' => [Money::rub(-12345), '-123,45 ₽'],
    'ведущий ноль копеек' => [Money::rub(105), '1,05 ₽'],
    'другая валюта' => [Money::fromMinor(500, 'USD'), '5,00 USD'],
]);

it('приводится к строке так же, как форматируется', function (): void {
    expect((string) Money::rub(9900))->toBe('99,00 ₽');
});
