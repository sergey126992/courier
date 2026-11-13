<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;
use App\Livewire\Cabinet\TrackingLinkButton;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Публичная страница отслеживания (ЧТЗ 9.1, пункт 14; FR-UI-129)
|--------------------------------------------------------------------------
|
| Страница открывается только по подписанной ссылке без входа в систему,
| показывает белый список полей публичного трекинга и не раскрывает
| персональные данные и внутренние идентификаторы (FR-TRK-134).
|
*/

/**
 * Заказ с уникальными адресом доставки и отправителем.
 *
 * Значения выбраны так, чтобы их отсутствие в разметке проверялось поиском
 * по телу: улица, дом, координаты, имя, email и цена.
 */
function publicTrackingPageOrder(): DeliveryOrder
{
    $sender = User::factory()->sender()->create([
        'name' => 'Пётр Секретов',
        'email' => 'secret.sender@example.test',
    ]);

    return DeliveryOrder::factory()
        ->withStatus(OrderStatus::PickedUp)
        ->create([
            'sender_id' => $sender->getKey(),
            'tracking_code' => 'trk_publicpage01',
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
 * Подписанная ссылка публичной страницы, как её выдаёт кабинет.
 */
function signedPublicTrackingPageUrl(DeliveryOrder $order, ?CarbonInterface $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'tracking.public.web',
        $expiresAt ?? now()->addHour(),
        ['code' => $order->tracking_code],
    );
}

it('открывает страницу гостю по подписанной ссылке', function (): void {
    $order = publicTrackingPageOrder();

    $response = $this->get(signedPublicTrackingPageUrl($order))->assertOk();

    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/html');

    $response
        ->assertSee(__('tracking.labels.public_title'))
        ->assertSee(__('tracking.labels.status'))
        ->assertSee(__('tracking.labels.district'))
        ->assertSee(__('tracking.labels.eta'))
        ->assertSee(__('tracking.labels.source'))
        ->assertSee(__('tracking.labels.updated_at'))
        ->assertSee('Забран')
        ->assertSee('Северный');
});

it('не открывает страницу без подписи', function (): void {
    $order = publicTrackingPageOrder();

    $response = $this->get(route('tracking.public.web', ['code' => $order->tracking_code]));

    $response->assertForbidden();
    $response->assertSee(__('ui.errors.403.title'));
    $response->assertDontSee('Северный');
    $response->assertDontSee('Забран');

    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('отклоняет истёкшую подпись', function (): void {
    $order = publicTrackingPageOrder();

    $response = $this->get(signedPublicTrackingPageUrl($order, Carbon::now()->subMinute()));

    $response->assertForbidden();
    $response->assertSee(__('ui.errors.403.title'));
    $response->assertDontSee('Северный');
});

it('отклоняет подделанную подпись', function (): void {
    $order = publicTrackingPageOrder();
    $url = signedPublicTrackingPageUrl($order);
    $tampered = (string) preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url);

    $response = $this->get($tampered);

    $response->assertForbidden();
    $response->assertSee(__('ui.errors.403.title'));
    $response->assertDontSee('Северный');
});

it('отдаёт 404 по несуществующему коду при валидной подписи', function (): void {
    $url = URL::temporarySignedRoute('tracking.public.web', now()->addHour(), ['code' => 'trk_missing']);

    $response = $this->get($url);

    $response->assertNotFound();
    $response->assertSee(__('ui.errors.404.title'));
});

it('не раскрывает персональные данные и внутренние идентификаторы', function (): void {
    $order = publicTrackingPageOrder();

    $response = $this->get(signedPublicTrackingPageUrl($order))->assertOk();
    $content = (string) $response->getContent();

    expect($content)->not->toContain('Секретная')
        ->and($content)->not->toContain('55.768279')
        ->and($content)->not->toContain('37.6173')
        ->and($content)->not->toContain('Пётр Секретов')
        ->and($content)->not->toContain('secret.sender@example.test')
        ->and($content)->not->toContain('19400')
        ->and($content)->not->toContain($order->number)
        ->and($content)->not->toContain($order->tracking_code)
        ->and($content)->not->toContain('sender_id');
});

it('не меняет данные при повторном открытии', function (): void {
    $order = publicTrackingPageOrder();
    $url = signedPublicTrackingPageUrl($order);

    $updatedAt = (string) $order->updated_at;

    $first = $this->get($url)->assertOk();

    // Livewire 4 отдаёт блок @livewireStyles только один раз на экземпляр
    // приложения, а тест переиспользует его. Сбрасываем состояние так же,
    // как это происходит на новом HTTP-запросе в рабочем окружении.
    Livewire::flushState();

    $second = $this->get($url)->assertOk();

    expect($second->getContent())->toBe($first->getContent())
        ->and(OutboxMessage::query()->count())->toBe(0)
        ->and($order->fresh()?->status)->toBe(OrderStatus::PickedUp)
        ->and((string) $order->fresh()?->updated_at)->toBe($updatedAt);
});

it('ограничивает частоту публичных обращений по IP', function (): void {
    // Значение лимита уменьшается в тесте, а не увеличивается в конфигурации.
    config([
        'rate_limits.tracking_public.attempts' => 1,
        'rate_limits.tracking_public.decay_seconds' => 60,
    ]);

    $order = publicTrackingPageOrder();
    $url = signedPublicTrackingPageUrl($order);

    $this->get($url)->assertOk();

    $response = $this->get($url);

    $response->assertStatus(429);
    $response->assertSee(__('ui.errors.429.title'));

    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('выдаёт в кабинете ссылку, которая открывает публичную страницу', function (): void {
    $order = publicTrackingPageOrder();
    $sender = User::query()->findOrFail($order->sender_id);

    expect(Route::has('tracking.public.web'))->toBeTrue();

    $this->actingAs($sender);

    $component = Livewire::test(TrackingLinkButton::class, ['orderId' => $order->getKey()])
        ->call('create')
        ->assertSet('error', null);

    $url = (string) $component->get('url');

    expect($url)->toContain('/tracking/'.$order->tracking_code);

    $this->get($url)
        ->assertOk()
        ->assertSee('Северный');
});
