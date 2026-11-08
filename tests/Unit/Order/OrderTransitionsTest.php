<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTransitions;

it('разрешает переходы из таблицы ЧТЗ 5.2', function (OrderStatus $from, OrderStatus $to): void {
    expect(OrderTransitions::isAllowed($from, $to))->toBeTrue();

    // Проверка не бросает исключение — переход допустим.
    OrderTransitions::assertAllowed($from, $to);
})->with([
    'создан → назначен' => [OrderStatus::Created, OrderStatus::Assigned],
    'создан → отменён' => [OrderStatus::Created, OrderStatus::Cancelled],
    'назначен → принят' => [OrderStatus::Assigned, OrderStatus::Accepted],
    'назначен → отменён' => [OrderStatus::Assigned, OrderStatus::Cancelled],
    'принят → забран' => [OrderStatus::Accepted, OrderStatus::PickedUp],
    'принят → отменён' => [OrderStatus::Accepted, OrderStatus::Cancelled],
    'забран → вручён' => [OrderStatus::PickedUp, OrderStatus::Delivered],
    'забран → не выполнен' => [OrderStatus::PickedUp, OrderStatus::Failed],
    'вручён → закрыт' => [OrderStatus::Delivered, OrderStatus::Closed],
    'не выполнен → создан' => [OrderStatus::Failed, OrderStatus::Created],
]);

it('запрещает переходы вне таблицы', function (OrderStatus $from, OrderStatus $to): void {
    expect(OrderTransitions::isAllowed($from, $to))->toBeFalse();

    expect(static fn () => OrderTransitions::assertAllowed($from, $to))
        ->toThrow(OrderStatusTransitionNotAllowed::class);
})->with([
    'отмена после забора' => [OrderStatus::PickedUp, OrderStatus::Cancelled],
    'отмена после вручения' => [OrderStatus::Delivered, OrderStatus::Cancelled],
    'повторное назначение' => [OrderStatus::Assigned, OrderStatus::Assigned],
    'принятие без назначения' => [OrderStatus::Created, OrderStatus::Accepted],
    'забор без принятия' => [OrderStatus::Assigned, OrderStatus::PickedUp],
    'вручение без забора' => [OrderStatus::Accepted, OrderStatus::Delivered],
    'закрытие до вручения' => [OrderStatus::PickedUp, OrderStatus::Closed],
    'возврат из закрытого' => [OrderStatus::Closed, OrderStatus::Created],
    'переход из отменённого' => [OrderStatus::Cancelled, OrderStatus::Assigned],
    'не выполнен → отменён' => [OrderStatus::Failed, OrderStatus::Cancelled],
    'создан → забран' => [OrderStatus::Created, OrderStatus::PickedUp],
]);

it('отдаёт целевые статусы из текущего', function (): void {
    expect(OrderTransitions::targets(OrderStatus::Created))->toBe([OrderStatus::Assigned, OrderStatus::Cancelled])
        ->and(OrderTransitions::targets(OrderStatus::PickedUp))->toBe([OrderStatus::Delivered, OrderStatus::Failed])
        ->and(OrderTransitions::targets(OrderStatus::Delivered))->toBe([OrderStatus::Closed])
        ->and(OrderTransitions::targets(OrderStatus::Failed))->toBe([OrderStatus::Created]);
});

it('не выпускает из терминальных статусов', function (OrderStatus $status): void {
    expect(OrderTransitions::targets($status))->toBe([]);
})->with([
    'закрыт' => [OrderStatus::Closed],
    'отменён' => [OrderStatus::Cancelled],
]);

it('несёт текущий статус и попытку в исключении', function (): void {
    try {
        OrderTransitions::assertAllowed(OrderStatus::PickedUp, OrderStatus::Cancelled);
    } catch (OrderStatusTransitionNotAllowed $exception) {
        expect($exception->fromStatus())->toBe(OrderStatus::PickedUp)
            ->and($exception->toStatus())->toBe(OrderStatus::Cancelled)
            ->and($exception->getMessage())->toContain('Забран')
            ->and($exception->getMessage())->toContain('Отменён');

        return;
    }

    $this->fail('Ожидалось исключение о запрещённом переходе.');
});
