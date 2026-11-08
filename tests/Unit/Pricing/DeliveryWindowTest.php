<?php

declare(strict_types=1);

use App\Domain\Pricing\DeliveryWindow;
use App\Domain\Pricing\Exceptions\InvalidDeliveryWindow;

it('создаёт окно доставки и считает момент прибытия', function (): void {
    $requestedAt = new DateTimeImmutable('2026-03-10 15:00:00');
    $window = new DeliveryWindow($requestedAt, 45);

    expect($window->requestedAt())->toEqual($requestedAt)
        ->and($window->etaMinutes())->toBe(45)
        ->and($window->arrivesAt()->format('H:i'))->toBe('15:45');
});

it('отклоняет неположительный прогноз', function (int $etaMinutes): void {
    expect(static fn () => new DeliveryWindow(new DateTimeImmutable('2026-03-10 15:00:00'), $etaMinutes))
        ->toThrow(InvalidDeliveryWindow::class);
})->with([
    'ноль' => [0],
    'отрицательный' => [-10],
]);
