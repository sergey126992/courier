<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Заказ отправителя с заданным временем создания.
 */
function listedOrder(User $sender, OrderStatus $status = OrderStatus::Created, ?string $createdAt = null): DeliveryOrder
{
    return DeliveryOrder::factory()->withStatus($status)->create([
        'sender_id' => $sender->id,
        'created_at' => $createdAt ?? now()->toDateTimeString(),
        'updated_at' => $createdAt ?? now()->toDateTimeString(),
    ]);
}

it('отдаёт отправителю только его заказы', function (): void {
    $sender = User::factory()->sender()->create();
    $other = User::factory()->sender()->create();
    listedOrder($sender);
    listedOrder($other);

    Sanctum::actingAs($sender);

    $response = $this->getJson('/api/v1/orders')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.number'))->toBe(DeliveryOrder::query()->where('sender_id', $sender->id)->sole()->number);
});

it('отдаёт диспетчеру заказы всех отправителей', function (): void {
    listedOrder(User::factory()->sender()->create());
    listedOrder(User::factory()->sender()->create());

    Sanctum::actingAs(User::factory()->dispatcher()->create());

    expect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->toHaveCount(2);
});

it('пагинирует курсором без дублей и пропусков', function (): void {
    $sender = User::factory()->sender()->create();

    for ($index = 0; $index < 25; $index++) {
        listedOrder($sender, OrderStatus::Created, now()->subMinutes(25 - $index)->toDateTimeString());
    }

    Sanctum::actingAs($sender);

    $first = $this->getJson('/api/v1/orders?limit=10')->assertOk();
    $numbers = array_map(
        static fn (array $order): string => (string) $order['number'],
        (array) $first->json('data'),
    );

    expect($numbers)->toHaveCount(10)
        ->and($first->json('pagination.per_page'))->toBe(10)
        ->and($first->json('pagination.next'))->not->toBeNull();

    $next = $first->json('pagination.next');

    while (is_string($next)) {
        $page = $this->getJson($next)->assertOk();

        foreach ((array) $page->json('data') as $order) {
            $numbers[] = (string) $order['number'];
        }

        $next = $page->json('pagination.next');
    }

    expect($numbers)->toHaveCount(25)
        ->and(array_unique($numbers))->toHaveCount(25);
});

it('фильтрует по статусу, в том числе по нескольким', function (): void {
    $sender = User::factory()->sender()->create();
    listedOrder($sender, OrderStatus::Created);
    listedOrder($sender, OrderStatus::Created);
    listedOrder($sender, OrderStatus::Cancelled);

    Sanctum::actingAs($sender);

    $cancelled = $this->getJson('/api/v1/orders?status[]=cancelled')->assertOk();
    expect($cancelled->json('data'))->toHaveCount(1)
        ->and($cancelled->json('data.0.status'))->toBe('cancelled');

    $both = $this->getJson('/api/v1/orders?status[]=created&status[]=cancelled')->assertOk();
    expect($both->json('data'))->toHaveCount(3);
});

it('фильтрует по периоду создания', function (): void {
    $sender = User::factory()->sender()->create();
    listedOrder($sender, OrderStatus::Created, now()->subDays(3)->toDateTimeString());
    listedOrder($sender, OrderStatus::Created, now()->toDateTimeString());

    Sanctum::actingAs($sender);

    $from = now()->subDay()->toIso8601String();
    $response = $this->getJson('/api/v1/orders?from='.urlencode($from))->assertOk();

    expect($response->json('data'))->toHaveCount(1);

    $to = now()->subDays(2)->toIso8601String();
    $older = $this->getJson('/api/v1/orders?to='.urlencode($to))->assertOk();

    expect($older->json('data'))->toHaveCount(1);
});

it('отклоняет недопустимые фильтры и сортировку', function (string $query, string $field): void {
    Sanctum::actingAs(User::factory()->sender()->create());

    $response = $this->getJson('/api/v1/orders?'.$query);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    $fields = array_map(
        static fn (array $item): string => (string) $item['field'],
        (array) $response->json('details'),
    );

    expect($fields)->toContain($field);
})->with([
    'неизвестная сортировка' => ['sort=price', 'sort'],
    'неизвестный статус' => ['status[]=draft', 'status.0'],
    'слишком большой размер страницы' => ['limit=1000', 'limit'],
]);

it('требует токен для списка', function (): void {
    $this->getJson('/api/v1/orders')->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('закрывает список ролям без доступа', function (Role $role): void {
    $user = $role === Role::Courier
        ? User::factory()->courier()->create()
        : User::factory()->admin()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/orders')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'курьер' => [Role::Courier],
    'администратор' => [Role::Admin],
]);
