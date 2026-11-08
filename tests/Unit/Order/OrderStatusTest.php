<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;

it('отдаёт человекочитаемое название статуса', function (OrderStatus $status, string $label): void {
    expect($status->label())->toBe($label);
})->with([
    'создан' => [OrderStatus::Created, 'Создан'],
    'назначен' => [OrderStatus::Assigned, 'Назначен'],
    'принят' => [OrderStatus::Accepted, 'Принят'],
    'забран' => [OrderStatus::PickedUp, 'Забран'],
    'вручён' => [OrderStatus::Delivered, 'Вручён'],
    'закрыт' => [OrderStatus::Closed, 'Закрыт'],
    'отменён' => [OrderStatus::Cancelled, 'Отменён'],
    'не выполнен' => [OrderStatus::Failed, 'Не выполнен'],
]);

it('считает терминальными только закрытый и отменённый заказ', function (): void {
    $terminal = array_values(array_filter(
        OrderStatus::cases(),
        static fn (OrderStatus $status): bool => $status->isTerminal(),
    ));

    expect($terminal)->toBe([OrderStatus::Closed, OrderStatus::Cancelled]);
});

it('не считает «не выполнен» терминальным: заказ возвращается в работу', function (): void {
    expect(OrderStatus::Failed->isTerminal())->toBeFalse()
        ->and(OrderStatus::Failed->isActive())->toBeTrue();
});

it('делит статусы на активные и терминальные без пересечений', function (): void {
    foreach (OrderStatus::cases() as $status) {
        expect($status->isActive())->toBe(! $status->isTerminal());
    }
});
