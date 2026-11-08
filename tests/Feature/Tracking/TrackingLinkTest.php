<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Адрес создания публичной ссылки трекинга.
 */
function trackingLinkUrl(DeliveryOrder $order): string
{
    return '/api/v1/orders/'.$order->getKey().'/tracking-link';
}

/**
 * Заказ отправителя вместе с владельцем.
 *
 * @return array{0: DeliveryOrder, 1: User}
 */
function trackingLinkOrder(): array
{
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->getKey()]);

    return [$order, $sender];
}

it('выдаёт владельцу подписанную ссылку со сроком из конфигурации', function (): void {
    Carbon::setTestNow('2026-05-12 10:00:00');
    [$order, $sender] = trackingLinkOrder();

    Sanctum::actingAs($sender);

    $response = $this->getJson(trackingLinkUrl($order))->assertOk();

    $url = (string) $response->json('data.url');
    $expiresAt = (string) $response->json('data.expires_at');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($response->json('data'))->toHaveKeys(['url', 'expires_at'])
        ->and($url)->toContain('/api/v1/tracking/'.$order->tracking_code)
        ->and($url)->toContain('signature=')
        ->and($expiresAt)->toBe(now()->addHours(168)->toIso8601String())
        // Срок в подписи совпадает с expires_at ответа.
        ->and((int) $query['expires'])->toBe(now()->addHours(168)->timestamp);
});

it('выдаёт ссылку диспетчеру', function (): void {
    [$order] = trackingLinkOrder();

    Sanctum::actingAs(User::factory()->dispatcher()->create());

    $this->getJson(trackingLinkUrl($order))
        ->assertOk()
        ->assertJsonPath('data.expires_at', static fn (mixed $value): bool => is_string($value));
});

it('создаёт новую ссылку с новым сроком при повторном запросе', function (): void {
    Carbon::setTestNow('2026-05-12 10:00:00');
    [$order, $sender] = trackingLinkOrder();

    Sanctum::actingAs($sender);

    $first = $this->getJson(trackingLinkUrl($order))->assertOk();

    Carbon::setTestNow('2026-05-13 10:00:00');

    $second = $this->getJson(trackingLinkUrl($order))->assertOk();

    expect($second->json('data.expires_at'))->not->toBe($first->json('data.expires_at'))
        ->and(Carbon::parse((string) $second->json('data.expires_at'))->timestamp)
        ->toBeGreaterThan(Carbon::parse((string) $first->json('data.expires_at'))->timestamp);
});

it('не хранит ссылку в базе: записи и события не создаются', function (): void {
    [$order, $sender] = trackingLinkOrder();
    $updatedAt = (string) $order->updated_at;

    Sanctum::actingAs($sender);

    $this->getJson(trackingLinkUrl($order))->assertOk();

    expect(OutboxMessage::query()->count())->toBe(0)
        ->and((string) $order->fresh()?->updated_at)->toBe($updatedAt);
});

it('не раскрывает чужой заказ отправителю', function (): void {
    [$order] = trackingLinkOrder();

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->getJson(trackingLinkUrl($order))
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('не выдаёт ссылку ролям без доступа к заказам', function (User $user): void {
    [$order] = trackingLinkOrder();

    Sanctum::actingAs($user);

    // Единообразно с контрактом: чужой заказ и роль без доступа — 404 (ЧТЗ 8.7).
    $this->getJson(trackingLinkUrl($order))
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
})->with([
    'курьер' => fn (): User => User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create(),
    'администратор' => fn (): User => User::factory()->admin()->create(),
]);

it('возвращает 404 по несуществующему заказу', function (): void {
    Sanctum::actingAs(User::factory()->sender()->create());

    $this->getJson('/api/v1/orders/999999/tracking-link')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('требует авторизации', function (): void {
    [$order] = trackingLinkOrder();

    $this->getJson(trackingLinkUrl($order))->assertUnauthorized();
});
