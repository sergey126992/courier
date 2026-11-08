<?php

declare(strict_types=1);

use App\Actions\Tracking\CreateTrackingLink;
use App\Domain\Order\OrderStatus;
use App\Livewire\Cabinet\TrackingLinkButton;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Публичная ссылка трекинга в кабинете (ЧТЗ 9.2, пункт 18)
|--------------------------------------------------------------------------
|
| FR-UI-129: кнопка создаёт ссылку на web-маршрут
| config('ui.tracking.public_route') с подписью и показывает URL и срок;
| операция без параметра маршрута по-прежнему даёт API-ссылку.
|
*/

/**
 * Заказ отправителя вместе с владельцем.
 *
 * @return array{0: DeliveryOrder, 1: User}
 */
function cabinetTrackingLinkOrder(): array
{
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::PickedUp)->create([
        'sender_id' => $sender->id,
    ]);

    return [$order, $sender];
}

// HTML-страница публичного трекинга объявляется отдельным пунктом плана.
// Для проверки адреса ссылки маршрут регистрируется в тесте.
beforeEach(function (): void {
    Route::get('/tracking/{code}', static fn (): string => 'ok')->name('tracking.public.web');
});

it('создаёт ссылку на web-маршрут публичной страницы', function (): void {
    Carbon::setTestNow('2026-05-12 10:00:00');
    [$order, $sender] = cabinetTrackingLinkOrder();

    $this->actingAs($sender);

    $component = Livewire::test(TrackingLinkButton::class, ['orderId' => $order->id])
        ->call('create')
        ->assertSet('error', null)
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    $url = (string) $component->get('url');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toContain('/tracking/'.$order->tracking_code)
        ->and($url)->toContain('signature=')
        // Срок в подписи совпадает со сроком из конфигурации.
        ->and((int) $query['expires'])->toBe(now()->addHours(168)->timestamp)
        ->and($component->get('expiresAt'))->toBe(now()->addHours(168)->format((string) __('ui.formats.datetime')));
});

it('показывает адрес и срок действия ссылки', function (): void {
    Carbon::setTestNow('2026-05-12 10:00:00');
    [$order, $sender] = cabinetTrackingLinkOrder();

    $this->actingAs($sender);

    $component = Livewire::test(TrackingLinkButton::class, ['orderId' => $order->id])
        ->call('create');

    $component
        ->assertSee(__('tracking.labels.expires_at'))
        ->assertSee((string) $component->get('expiresAt'))
        ->assertSee((string) $component->get('url'))
        ->assertSee(__('tracking.labels.copy_link'));
});

it('оставляет прежнюю API-ссылку при вызове операции без параметра', function (): void {
    Carbon::setTestNow('2026-05-12 10:00:00');
    [$order, $sender] = cabinetTrackingLinkOrder();

    $snapshot = app(CreateTrackingLink::class)->handle($sender, $order);

    expect($snapshot->url)->toContain('/api/v1/tracking/'.$order->tracking_code)
        ->and($snapshot->url)->toContain('signature=');
});

it('не создаёт записи в базе при выдаче ссылки', function (): void {
    [$order, $sender] = cabinetTrackingLinkOrder();

    $this->actingAs($sender);

    Livewire::test(TrackingLinkButton::class, ['orderId' => $order->id])->call('create');

    expect(OutboxMessage::query()->count())->toBe(0);
});
