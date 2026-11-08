<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;
use App\Domain\Order\StatusTransition;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Заказ с уникальными адресом доставки и отправителем.
 *
 * Значения выбраны так, чтобы их отсутствие в публичном ответе проверялось
 * поиском по телу: улица, дом, координаты, имя и цена.
 */
function publicTrackingOrder(): DeliveryOrder
{
    $sender = User::factory()->sender()->create(['name' => 'Пётр Секретов']);

    return DeliveryOrder::factory()
        ->withStatus(OrderStatus::PickedUp)
        ->create([
            'sender_id' => $sender->getKey(),
            'tracking_code' => 'trk_public0001',
            'eta_minutes' => 22,
            'price_amount' => 19400,
            'dropoff_address' => [
                'district' => 'Северный',
                'street' => 'Секретная',
                'house' => '13',
                'latitude' => 55.768279,
                'longitude' => 37.6173,
            ],
        ]);
}

/**
 * Подписанная ссылка публичного трекинга, как её выдаёт приложение.
 */
function signedPublicTrackingUrl(DeliveryOrder $order, ?CarbonInterface $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'tracking.public',
        $expiresAt ?? now()->addHour(),
        ['code' => $order->tracking_code],
    );
}

it('отдаёт гостю строго ограниченный набор полей', function (): void {
    Carbon::setTestNow('2026-05-12 10:00:00');
    $order = publicTrackingOrder();
    $order->refresh();

    $response = $this->getJson(signedPublicTrackingUrl($order))->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe([
        'status',
        'status_label',
        'eta_minutes',
        'eta_at',
        'district',
        'updated_at',
    ])
        ->and($data['status'])->toBe('picked_up')
        ->and($data['status_label'])->toBe('Забран')
        ->and($data['eta_minutes'])->toBe(22)
        ->and($data['district'])->toBe('Северный')
        ->and($data['eta_at'])->toBe($order->requested_at->copy()->addMinutes(22)->toIso8601String())
        ->and($data['updated_at'])->toBe($order->updated_at->toIso8601String());
});

it('не раскрывает адрес, координаты, имя, цену и код трекинга', function (): void {
    $order = publicTrackingOrder();

    $response = $this->getJson(signedPublicTrackingUrl($order))->assertOk();
    $content = (string) $response->getContent();

    expect($content)->not->toContain('Секретная')
        ->and($content)->not->toContain('55.768279')
        ->and($content)->not->toContain('37.6173')
        ->and($content)->not->toContain('Пётр Секретов')
        ->and($content)->not->toContain('19400')
        ->and($content)->not->toContain($order->number)
        ->and($content)->not->toContain($order->tracking_code)
        ->and($response->json('data'))->not->toHaveKeys([
            'street', 'house', 'latitude', 'longitude', 'name', 'phone',
            'price_amount', 'number', 'tracking_code',
        ]);
});

it('отклоняет истёкшую подпись', function (): void {
    $order = publicTrackingOrder();

    $this->getJson(signedPublicTrackingUrl($order, Carbon::now()->subMinute()))
        ->assertForbidden()
        ->assertJsonPath('code', 'INVALID_SIGNATURE');
});

it('отклоняет подделанную подпись', function (): void {
    $order = publicTrackingOrder();
    $url = signedPublicTrackingUrl($order);
    $tampered = (string) preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url);

    $this->getJson($tampered)
        ->assertForbidden()
        ->assertJsonPath('code', 'INVALID_SIGNATURE');
});

it('возвращает 404 по несуществующему коду при валидной подписи', function (): void {
    $url = URL::temporarySignedRoute('tracking.public', now()->addHour(), ['code' => 'trk_missing']);

    $this->getJson($url)
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('ограничивает частоту публичных обращений по IP', function (): void {
    // Значение лимита уменьшается в тесте, а не увеличивается в конфигурации.
    config([
        'rate_limits.tracking_public.attempts' => 1,
        'rate_limits.tracking_public.decay_seconds' => 60,
    ]);

    $order = publicTrackingOrder();
    $url = signedPublicTrackingUrl($order);

    $this->getJson($url)->assertOk();

    $this->getJson($url)
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');
});

it('не обращается к базе повторно в окне кэша', function (): void {
    $order = publicTrackingOrder();
    $url = signedPublicTrackingUrl($order);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $this->getJson($url)->assertOk();
    expect($queries)->toBeGreaterThan(0);

    $queries = 0;
    $second = $this->getJson($url)->assertOk();

    expect($queries)->toBe(0)
        ->and($second->json('data'))->toBe($first->json('data'));
});

it('сбрасывает кэш при смене статуса заказа', function (): void {
    $order = publicTrackingOrder();

    $this->getJson(signedPublicTrackingUrl($order))
        ->assertOk()
        ->assertJsonPath('data.status', 'picked_up');

    $order->applyTransition(new StatusTransition(OrderStatus::PickedUp, OrderStatus::Delivered));

    $this->getJson(signedPublicTrackingUrl($order))
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.status_label', 'Вручён');
});

it('не создаёт событий и не меняет заказ', function (): void {
    $order = publicTrackingOrder();
    $updatedAt = (string) $order->updated_at;

    $this->getJson(signedPublicTrackingUrl($order))->assertOk();

    expect(OutboxMessage::query()->count())->toBe(0)
        ->and($order->fresh()?->status)->toBe(OrderStatus::PickedUp)
        ->and((string) $order->fresh()?->updated_at)->toBe($updatedAt);
});
