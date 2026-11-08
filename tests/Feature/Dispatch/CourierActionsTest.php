<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\FailureReason;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Курьер с действующим токеном.
 */
function courierActionsCourier(): User
{
    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();
    Sanctum::actingAs($courier);

    return $courier;
}

/**
 * Назначение в согласованной паре статусов заказа и назначения.
 */
function courierActionsAssignment(
    User $courier,
    AssignmentStatus $assignmentStatus,
    OrderStatus $orderStatus,
): Assignment {
    $order = DeliveryOrder::factory()->withStatus($orderStatus)->create();

    return Assignment::factory()->create([
        'order_id' => $order->id,
        'courier_id' => $courier->id,
        'status' => $assignmentStatus,
        'accepted_at' => $assignmentStatus === AssignmentStatus::Assigned ? null : now(),
        'picked_up_at' => in_array(
            $assignmentStatus,
            [AssignmentStatus::PickedUp, AssignmentStatus::Delivered, AssignmentStatus::Failed],
            true,
        ) ? now() : null,
        'delivered_at' => $assignmentStatus === AssignmentStatus::Delivered ? now() : null,
    ]);
}

/**
 * Адрес действия курьера по назначению.
 */
function courierActionUrl(Assignment $assignment, string $action): string
{
    return '/api/v1/assignments/'.$assignment->id.'/'.$action;
}

it('принимает назначение и пишет историю с автором-курьером', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::Assigned, OrderStatus::Assigned);

    $this->postJson(courierActionUrl($assignment, 'accept'))
        ->assertOk()
        ->assertJsonPath('data.id', $assignment->id)
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.status_label', 'Принято')
        ->assertJsonPath('data.order.status', 'accepted')
        ->assertJsonPath('data.order.status_label', 'Принят');

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::Accepted)
        ->and($assignment->accepted_at)->not->toBeNull()
        ->and($assignment->picked_up_at)->toBeNull()
        ->and($assignment->order()->firstOrFail()->status)->toBe(OrderStatus::Accepted);

    $history = OrderStatusHistory::query()->where('order_id', $assignment->order_id)->sole();

    expect($history->from_status)->toBe(OrderStatus::Assigned)
        ->and($history->to_status)->toBe(OrderStatus::Accepted)
        ->and($history->changed_by)->toBe($courier->id);

    // Принятие события не порождает: лента событий этапа начинается с забора.
    expect(OutboxMessage::query()->count())->toBe(0);
});

it('отмечает забор посылки и пишет событие order.picked_up', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::Accepted, OrderStatus::Accepted);

    $this->postJson(courierActionUrl($assignment, 'pickup'))
        ->assertOk()
        ->assertJsonPath('data.status', 'picked_up')
        ->assertJsonPath('data.status_label', 'Забрано')
        ->assertJsonPath('data.picked_up_at', static fn (mixed $value): bool => is_string($value))
        ->assertJsonPath('data.order.status', 'picked_up');

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::PickedUp)
        ->and($assignment->picked_up_at)->not->toBeNull()
        ->and($assignment->order()->firstOrFail()->status)->toBe(OrderStatus::PickedUp);

    $event = OutboxMessage::query()->where('event_type', 'order.picked_up')->sole();

    expect($event->aggregate_type)->toBe('order')
        ->and($event->aggregate_id)->toBe($assignment->order_id)
        ->and($event->payload)->toMatchArray([
            'order_id' => $assignment->order_id,
            'assignment_id' => $assignment->id,
            'courier_id' => $courier->id,
            'status' => 'picked_up',
        ])
        ->and($event->payload['picked_up_at'])->toBeString();

    expect(OrderStatusHistory::query()->sole()->changed_by)->toBe($courier->id);
});

it('отмечает вручение и пишет событие order.delivered', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::PickedUp, OrderStatus::PickedUp);

    $this->postJson(courierActionUrl($assignment, 'deliver'))
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.status_label', 'Вручено')
        ->assertJsonPath('data.delivered_at', static fn (mixed $value): bool => is_string($value))
        ->assertJsonPath('data.order.status', 'delivered');

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::Delivered)
        ->and($assignment->delivered_at)->not->toBeNull()
        ->and($assignment->order()->firstOrFail()->status)->toBe(OrderStatus::Delivered);

    $event = OutboxMessage::query()->where('event_type', 'order.delivered')->sole();

    expect($event->payload)->toMatchArray([
        'order_id' => $assignment->order_id,
        'assignment_id' => $assignment->id,
        'courier_id' => $courier->id,
        'status' => 'delivered',
    ])
        ->and($event->payload['delivered_at'])->toBeString();
});

it('отклоняет недопустимый переход: забор из «назначено»', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::Assigned, OrderStatus::Assigned);

    $this->postJson(courierActionUrl($assignment, 'pickup'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED')
        ->assertJsonPath('details.0.field', 'status');

    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Assigned)
        ->and(OrderStatusHistory::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('отклоняет действие по завершённому назначению', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::Delivered, OrderStatus::Delivered);

    $this->postJson(courierActionUrl($assignment, 'deliver'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ASSIGNMENT_NOT_ACTIVE');

    expect(OrderStatusHistory::query()->count())->toBe(0);
});

it('скрывает чужое назначение за тем же 404, что и несуществующее', function (): void {
    courierActionsCourier();
    $foreign = courierActionsAssignment(
        User::factory()->courier()->create(),
        AssignmentStatus::Assigned,
        OrderStatus::Assigned,
    );

    $foreignResponse = $this->postJson(courierActionUrl($foreign, 'accept'))
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $missingResponse = $this->postJson('/api/v1/assignments/999999/accept')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect($foreignResponse->json('code'))->toBe($missingResponse->json('code'))
        ->and($foreignResponse->json('title'))->toBe($missingResponse->json('title'))
        ->and($foreignResponse->json('detail'))->toBe($missingResponse->json('detail'));

    expect($foreign->refresh()->status)->toBe(AssignmentStatus::Assigned);
});

it('не даёт ролям без курьера выполнять действия', function (string $role): void {
    $assignment = courierActionsAssignment(
        User::factory()->courier()->create(),
        AssignmentStatus::Assigned,
        OrderStatus::Assigned,
    );

    $user = match ($role) {
        'dispatcher' => User::factory()->dispatcher()->create(),
        'admin' => User::factory()->admin()->create(),
        default => User::factory()->sender()->create(),
    };

    Sanctum::actingAs($user);

    $this->postJson(courierActionUrl($assignment, 'accept'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Assigned);
})->with([
    'диспетчер' => ['dispatcher'],
    'администратор' => ['admin'],
    'отправитель' => ['sender'],
]);

it('не создаёт вторую запись истории при повторе действия', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::Assigned, OrderStatus::Assigned);

    $this->postJson(courierActionUrl($assignment, 'accept'))->assertOk();

    $this->postJson(courierActionUrl($assignment, 'accept'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED');

    expect(OrderStatusHistory::query()->where('order_id', $assignment->order_id)->count())->toBe(1);
});

it('фиксирует неудачную попытку с причиной и комментарием', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::PickedUp, OrderStatus::PickedUp);

    $this->postJson(courierActionUrl($assignment, 'fail'), [
        'reason' => 'recipient_unavailable',
        'comment' => 'Домофон не отвечает',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.status_label', 'Не выполнено')
        ->assertJsonPath('data.failure_reason', 'recipient_unavailable')
        ->assertJsonPath('data.failure_comment', 'Домофон не отвечает')
        ->assertJsonPath('data.failed_at', static fn (mixed $value): bool => is_string($value))
        ->assertJsonPath('data.order.status', 'failed');

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::Failed)
        ->and($assignment->failure_reason)->toBe(FailureReason::RecipientUnavailable)
        ->and($assignment->failure_comment)->toBe('Домофон не отвечает')
        ->and($assignment->failed_at)->not->toBeNull()
        ->and($assignment->order()->firstOrFail()->status)->toBe(OrderStatus::Failed);

    // Причина дублируется кодом в историю статусов заказа (ЧТЗ 12.1).
    $history = OrderStatusHistory::query()->where('order_id', $assignment->order_id)->sole();

    expect($history->from_status)->toBe(OrderStatus::PickedUp)
        ->and($history->to_status)->toBe(OrderStatus::Failed)
        ->and($history->reason)->toBe('recipient_unavailable')
        ->and($history->changed_by)->toBe($courier->id);

    $event = OutboxMessage::query()->where('event_type', 'order.failed')->sole();

    expect($event->payload)->toMatchArray([
        'order_id' => $assignment->order_id,
        'assignment_id' => $assignment->id,
        'courier_id' => $courier->id,
        'status' => 'failed',
    ])
        ->and($event->payload)->not->toHaveKey('failure_comment')
        ->and($event->payload['failed_at'])->toBeString();
});

it('фиксирует неудачу без комментария', function (): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::PickedUp, OrderStatus::PickedUp);

    $this->postJson(courierActionUrl($assignment, 'fail'), ['reason' => 'other'])
        ->assertOk()
        ->assertJsonPath('data.failure_reason', 'other')
        ->assertJsonPath('data.failure_comment', null);
});

it('отклоняет причину вне перечня и слишком длинный комментарий', function (array $payload, string $field): void {
    $courier = courierActionsCourier();
    $assignment = courierActionsAssignment($courier, AssignmentStatus::PickedUp, OrderStatus::PickedUp);

    $this->postJson(courierActionUrl($assignment, 'fail'), $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', $field);

    expect($assignment->refresh()->status)->toBe(AssignmentStatus::PickedUp);
})->with([
    'причина вне перечня' => [['reason' => 'lost_parcel'], 'reason'],
    'без причины' => [[], 'reason'],
    'комментарий длиннее 500' => [['reason' => 'other', 'comment' => str_repeat('а', 501)], 'comment'],
]);
