<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Domain\Order\OrderStatus;

it('создаёт заказ в статусе «создан»', function (): void {
    $order = orderAggregate();

    expect($order->status())->toBe(OrderStatus::Created)
        ->and($order->number()->value())->toBe('CRG-2026-000001')
        ->and($order->trackingCode()->value())->toBe('trk_9f3a1c5e')
        ->and($order->price()->amount())->toBe(19400)
        ->and($order->etaMinutes())->toBe(15)
        ->and($order->distance()->kilometers())->toBe(1.8)
        ->and($order->breakdown())->toHaveCount(3)
        ->and($order->pickupAddress()->house())->toBe('7')
        ->and($order->dropoffAddress()->house())->toBe('9')
        ->and($order->parcel()->weightGrams())->toBe(1500)
        ->and($order->transport()->value)->toBe('light');
});

it('ведёт заказ по всей цепочке до закрытия', function (): void {
    $order = orderAggregate();

    $assigned = $order->assign();
    $accepted = $order->accept();
    $pickedUp = $order->pickup();
    $delivered = $order->deliver();
    $closed = $order->close();

    expect($assigned->from())->toBe(OrderStatus::Created)
        ->and($assigned->to())->toBe(OrderStatus::Assigned)
        ->and($accepted->to())->toBe(OrderStatus::Accepted)
        ->and($pickedUp->to())->toBe(OrderStatus::PickedUp)
        ->and($delivered->to())->toBe(OrderStatus::Delivered)
        ->and($closed->from())->toBe(OrderStatus::Delivered)
        ->and($closed->to())->toBe(OrderStatus::Closed)
        ->and($order->status())->toBe(OrderStatus::Closed);
});

it('отменяет заказ до забора', function (OrderStatus $status): void {
    $order = orderAggregate($status);

    $transition = $order->cancel('Отправитель передумал');

    expect($transition->from())->toBe($status)
        ->and($transition->to())->toBe(OrderStatus::Cancelled)
        ->and($transition->reason())->toBe('Отправитель передумал')
        ->and($order->status())->toBe(OrderStatus::Cancelled);
})->with([
    'создан' => [OrderStatus::Created],
    'назначен' => [OrderStatus::Assigned],
    'принят' => [OrderStatus::Accepted],
]);

it('запрещает отмену после забора посылки', function (OrderStatus $status): void {
    $order = orderAggregate($status);

    expect(static fn () => $order->cancel())
        ->toThrow(OrderStatusTransitionNotAllowed::class);

    // Статус не изменился: неудачный переход не портит заказ.
    expect($order->status())->toBe($status);
})->with([
    'забран' => [OrderStatus::PickedUp],
    'вручён' => [OrderStatus::Delivered],
    'закрыт' => [OrderStatus::Closed],
    'отменён' => [OrderStatus::Cancelled],
]);

it('помечает неудачную попытку и возвращает заказ в работу', function (): void {
    $order = orderAggregate(OrderStatus::PickedUp);

    $failed = $order->fail('Клиент не открыл дверь');
    $reassigned = $order->reassign();

    expect($failed->to())->toBe(OrderStatus::Failed)
        ->and($failed->reason())->toBe('Клиент не открыл дверь')
        ->and($reassigned->from())->toBe(OrderStatus::Failed)
        ->and($reassigned->to())->toBe(OrderStatus::Created)
        ->and($order->status())->toBe(OrderStatus::Created);
});

it('запрещает переназначение не из статуса «не выполнен»', function (): void {
    $order = orderAggregate();

    expect(static fn () => $order->reassign())
        ->toThrow(OrderStatusTransitionNotAllowed::class);
});

it('запрещает повторное назначение', function (): void {
    $order = orderAggregate(OrderStatus::Assigned);

    expect(static fn () => $order->assign())
        ->toThrow(OrderStatusTransitionNotAllowed::class);
});

it('не меняет статус при запрещённом переходе', function (): void {
    $order = orderAggregate(OrderStatus::Created);

    try {
        $order->deliver();
    } catch (OrderStatusTransitionNotAllowed $exception) {
        expect($exception->fromStatus())->toBe(OrderStatus::Created)
            ->and($exception->toStatus())->toBe(OrderStatus::Delivered)
            ->and($order->status())->toBe(OrderStatus::Created);

        return;
    }

    $this->fail('Ожидалось исключение о запрещённом переходе.');
});
