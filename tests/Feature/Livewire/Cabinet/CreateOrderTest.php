<?php

declare(strict_types=1);

use App\Livewire\Cabinet\CreateOrder;
use App\Models\Geography\AddressDirectoryEntry;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Расчёт и создание заказа отправителя (ЧТЗ 9.2, пункты 5–8)
|--------------------------------------------------------------------------
|
| FR-UI-121…123: расчёт заполняет результат и ничего не сохраняет, повтор
| с тем же ключом идемпотентности не создаёт второй заказ, ключ имеет длину
| 16–64 символа и не меняется между попытками, доменный отказ транспорта
| и адреса показывается у поля, введённые данные сохраняются.
|
*/

/**
 * Временный маршрут карточки заказа: сама карточка появится в следующем
 * пункте плана, а редиректу после успеха нужен существующий адрес.
 */
function registerCabinetOrderShowRoute(): void
{
    Route::middleware(['web', 'auth'])
        ->get('/cabinet/orders/{order}', static fn (): string => '')
        ->name('cabinet.orders.show');

    Route::getRoutes()->refreshNameLookups();
}

/**
 * Пара адресов справочника для формы: забор и доставка в 1.8 км друг от друга.
 *
 * Расстояние совпадает с `orderPayload()`: цены тестов расчёта не меняются,
 * хотя координаты теперь приходят из справочника, а не из свойств формы.
 *
 * @return array{pickup: AddressDirectoryEntry, dropoff: AddressDirectoryEntry}
 */
function cabinetCreateOrderAddresses(): array
{
    $dropoff = estimateRoute(1.8)[1];

    return [
        'pickup' => addressInZone(),
        'dropoff' => addressInZone([
            'house' => '9',
            'latitude' => $dropoff['latitude'],
            'longitude' => $dropoff['longitude'],
        ]),
    ];
}

/**
 * Значения полей формы создания заказа в форме свойств компонента.
 *
 * Адресов здесь нет: они выбираются из справочника через
 * selectPickup()/selectDropoff(), поэтому параметры выбора задаются вызовами.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cabinetCreateOrderForm(array $overrides = []): array
{
    $payload = orderPayload();

    return array_merge([
        'parcel' => $payload['parcel'],
        'transport' => $payload['transport'],
        'lightVehicleKind' => $payload['light_vehicle_kind'],
        'requestedAt' => $payload['requested_at'],
    ], $overrides);
}

/**
 * Компонент формы с заполненными полями и выбранными адресами справочника.
 *
 * @param array{pickup: AddressDirectoryEntry, dropoff: AddressDirectoryEntry} $addresses
 */
function cabinetCreateOrderComponent(array $addresses): Testable
{
    $component = Livewire::test(CreateOrder::class);

    foreach (cabinetCreateOrderForm() as $property => $value) {
        $component->set($property, $value);
    }

    return $component
        ->call('selectPickup', $addresses['pickup']->id)
        ->call('selectDropoff', $addresses['dropoff']->id);
}

beforeEach(function (): void {
    estimateServiceZone();

    $this->actingAs(User::factory()->sender()->create());
});

it('закрывает форму создания заказа гостю редиректом на вход', function (): void {
    Auth::logout();

    $this->get('/cabinet/orders/create')->assertRedirect('/login');
});

it('запрещает форму создания заказа диспетчеру, курьеру и администратору', function (): void {
    $users = [
        User::factory()->dispatcher()->create(),
        User::factory()->courier()->create(),
        User::factory()->admin()->create(),
    ];

    foreach ($users as $user) {
        $this->actingAs($user)->get('/cabinet/orders/create')->assertForbidden();
    }
});

it('показывает отправителю форму расчёта и создания заказа', function (): void {
    $this->get('/cabinet/orders/create')
        ->assertOk()
        ->assertSee(__('ui.create_order.title'))
        ->assertSee(__('ui.actions.calculate'))
        ->assertSee(__('ui.actions.create_order'));
});

it('оставляет один акцент на итоговом действии и не дублирует заголовок', function (): void {
    $response = $this->get('/cabinet/orders/create')->assertOk();
    $html = (string) $response->getContent();

    $calculate = preg_quote(__('ui.actions.calculate'), '/');
    $create = preg_quote(__('ui.actions.create_order'), '/');

    // FR-UI-202: единственный акцент — итоговое действие, расчёт вторичный.
    expect(substr_count($html, 'btn btn-primary'))->toBe(1)
        ->and(preg_match('/<button[^>]*btn btn-secondary[^>]*>\s*'.$calculate.'/s', $html))->toBe(1)
        ->and(preg_match('/<button[^>]*btn btn-primary[^>]*>\s*'.$create.'/s', $html))->toBe(1);

    // Заголовок страницы один: карточка формы не повторяет `h2` (иерархия h1 → h2).
    $response->assertDontSeeHtml('<h2 class="card-title">'.__('ui.create_order.title').'</h2>');
});

it('не показывает поля координат и подписи «Широта» и «Долгота»', function (): void {
    $response = $this->get('/cabinet/orders/create')->assertOk();

    // Координаты вводятся только через справочник: полей и подписей нет (FR-UI-121).
    $response->assertDontSee('latitude')
        ->assertDontSee('longitude')
        ->assertDontSee('Широта')
        ->assertDontSee('Долгота');
});

it('отправляет неподтверждённого отправителя на подтверждение почты', function (): void {
    $sender = User::factory()->unverified()->sender()->create();

    $this->actingAs($sender)->get('/cabinet/orders/create')
        ->assertRedirect(route('verification.notice'));
});

it('рассчитывает доставку и не создаёт заказ', function (): void {
    $component = cabinetCreateOrderComponent(cabinetCreateOrderAddresses());
    $keyBefore = $component->get('idempotencyKey');

    $component->call('estimate')->assertHasNoErrors()->assertSee(__('ui.create_order.quote'));

    /** @var array<string, mixed> $quote */
    $quote = $component->get('quote');

    expect($quote)->toBeArray()
        ->and($quote['price_amount'])->toBe(19400)
        ->and($quote['currency'])->toBe('RUB')
        ->and($quote['eta_minutes'])->toBeGreaterThan(0)
        ->and($quote['lines'])->toHaveCount(3);

    // Расчёт ничего не сохраняет и не меняет ключ повтора.
    $this->assertDatabaseCount('delivery_orders', 0);
    expect($component->get('idempotencyKey'))->toBe($keyBefore);
});

it('создаёт один заказ при повторе с тем же ключом идемпотентности', function (): void {
    registerCabinetOrderShowRoute();

    $component = cabinetCreateOrderComponent(cabinetCreateOrderAddresses());

    $component->call('create')
        ->assertHasNoErrors()
        ->assertSessionHas('status', __('orders.messages.created'));

    $order = DeliveryOrder::query()->sole();

    $component->assertRedirect(route('cabinet.orders.show', ['order' => $order->id]));

    // Повтор формы: тот же заказ, второй записи нет (FR-UI-122).
    $component->call('create')
        ->assertRedirect(route('cabinet.orders.show', ['order' => $order->id]));

    $this->assertDatabaseCount('delivery_orders', 1);
    $this->assertDatabaseCount('order_status_history', 1);
});

it('генерирует ключ идемпотентности длиной 16–64 и не меняет его между попытками', function (): void {
    registerCabinetOrderShowRoute();

    $addresses = cabinetCreateOrderAddresses();
    $component = cabinetCreateOrderComponent($addresses);
    $key = $component->get('idempotencyKey');

    expect(mb_strlen($key))->toBeGreaterThanOrEqual(16)
        ->and(mb_strlen($key))->toBeLessThanOrEqual(64);

    // Неудачная попытка не сбрасывает ключ: повтор идёт с тем же значением.
    $component->call('selectPickup', addressOutsideZone()->id)
        ->call('create')
        ->assertHasErrors(['pickup']);

    expect($component->get('idempotencyKey'))->toBe($key);

    $component->call('selectPickup', $addresses['pickup']->id)->call('create');

    expect($component->get('idempotencyKey'))->toBe($key);
    $this->assertDatabaseCount('delivery_orders', 1);
});

it('показывает ошибку транспорта у поля и сохраняет данные формы', function (): void {
    $component = cabinetCreateOrderComponent(cabinetCreateOrderAddresses());
    $dropoffId = $component->get('dropoffAddressId');

    $component->set('transport', 'on_foot')
        ->set('lightVehicleKind', '')
        ->set('parcel', [
            'weight_grams' => '5000',
            'dimensions' => ['length' => '30', 'width' => '20', 'height' => '10'],
        ])
        ->call('estimate')
        ->assertHasErrors(['parcel.weight_grams']);

    // Данные формы на месте: редиректа и очистки нет (FR-UI-121, ЧТЗ 8.4).
    /** @var array<string, mixed> $parcel */
    $parcel = $component->get('parcel');

    expect($component->get('dropoffAddressId'))->toBe($dropoffId)
        ->and($parcel['weight_grams'])->toBe('5000')
        ->and($component->get('transport'))->toBe('on_foot');

    $this->assertDatabaseCount('delivery_orders', 0);
});

it('показывает отказ ADDRESS_OUTSIDE_SERVICE_AREA у поля адреса забора', function (): void {
    $component = cabinetCreateOrderComponent(cabinetCreateOrderAddresses());
    $outside = addressOutsideZone();

    $component->call('selectPickup', $outside->id)
        ->call('estimate')
        ->assertHasErrors(['pickup'])
        ->assertSee(__('geography.errors.ADDRESS_OUTSIDE_SERVICE_AREA', ['address' => 'забора']));
});

it('связывает ошибку вида лёгкого транспорта с полем через aria-describedby', function (): void {
    $component = cabinetCreateOrderComponent(cabinetCreateOrderAddresses());

    // Для «лёгкого» транспорта вид обязателен: ключ ошибки — имя поля подписи.
    $component->set('transport', 'light')
        ->set('lightVehicleKind', '')
        ->call('estimate')
        ->assertHasErrors(['light_vehicle_kind'])
        ->assertSeeHtml('id="light_vehicle_kind-error"')
        ->assertSeeHtml('aria-describedby="light_vehicle_kind-error"');
});

it('даёт всем полям формы доступное имя через label или aria-label', function (): void {
    $html = (string) $this->get('/cabinet/orders/create')->assertOk()->getContent();

    preg_match('/<form[^>]*wire:submit="estimate".*?<\/form>/s', $html, $matches);

    $form = $matches[0] ?? '';

    expect($form)->not->toBe('');

    preg_match_all('/<(?:input|select)\b[^>]*>/', $form, $controls);
    preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/', $form, $labels);
    preg_match_all('/\baria-label="([^"]+)"/', $form, $ariaLabels);

    $named = array_merge($labels[1], $ariaLabels[1]);

    $unnamed = [];

    foreach ($controls[0] as $control) {
        // Имя может задавать aria-label/aria-labelledby прямо на элементе.
        if (preg_match('/\baria-label(?:ledby)?="/', $control) === 1) {
            continue;
        }

        if (preg_match('/\bid="([^"]+)"/', $control, $id) !== 1
            || ! in_array($id[1], $named, true)) {
            $unnamed[] = $control;
        }
    }

    expect($controls[0])->not->toBeEmpty()
        ->and($unnamed)->toBe([]);
});

it('показывает подсказки после ввода двух символов', function (): void {
    cabinetCreateOrderAddresses();

    $component = Livewire::test(CreateOrder::class);

    // Один символ не ищется: справочник не тревожим.
    $component->set('pickupQuery', 'Т')->assertSet('pickupSuggestions', []);

    $component->set('pickupQuery', 'Твер');

    expect($component->get('pickupSuggestions'))->toHaveCount(2);

    // Подписи найденных адресов видны в разметке списка подсказок.
    $component->assertSee('Тверская, 7 — Тверской')
        ->assertSee('Тверская, 9 — Тверской');
});

it('заполняет строку поиска подписью выбранного адреса и снимает ошибку поля', function (): void {
    $addresses = cabinetCreateOrderAddresses();
    $component = Livewire::test(CreateOrder::class);

    // До выбора расчёт ругается на адрес.
    $component->call('estimate')->assertHasErrors(['pickup']);

    $component->set('pickupQuery', 'Твер')
        ->call('selectPickup', $addresses['pickup']->id)
        ->assertSet('pickupQuery', $addresses['pickup']->label())
        ->assertSet('pickupAddressId', $addresses['pickup']->id)
        ->assertSet('pickupSuggestions', [])
        ->assertHasNoErrors('pickup');
});

it('создаёт заказ с координатами записи справочника, а не с введённым текстом', function (): void {
    registerCabinetOrderShowRoute();

    $addresses = cabinetCreateOrderAddresses();
    $component = cabinetCreateOrderComponent($addresses);

    $component->call('create')->assertHasNoErrors();

    $order = DeliveryOrder::query()->sole();

    expect($order->pickup_address['district'])->toBe($addresses['pickup']->district)
        ->and($order->pickup_address['street'])->toBe($addresses['pickup']->street)
        ->and($order->pickup_address['house'])->toBe($addresses['pickup']->house)
        ->and((float) $order->pickup_address['latitude'])->toBe((float) $addresses['pickup']->latitude)
        ->and((float) $order->pickup_address['longitude'])->toBe((float) $addresses['pickup']->longitude)
        ->and((float) $order->dropoff_address['latitude'])->toBe((float) $addresses['dropoff']->latitude);
});

it('требует выбрать оба адреса до расчёта и создания', function (): void {
    $component = Livewire::test(CreateOrder::class);

    foreach (cabinetCreateOrderForm() as $property => $value) {
        $component->set($property, $value);
    }

    $component->call('estimate')
        ->assertHasErrors(['pickup', 'dropoff'])
        ->assertSee(__('ui.create_order.address_not_selected'));

    $component->call('create')->assertHasErrors(['pickup', 'dropoff']);

    // Actions не вызывались: ни заказа, ни занятого ключа идемпотентности.
    $this->assertDatabaseCount('delivery_orders', 0);
    $this->assertDatabaseCount('idempotency_keys', 0);
});

it('не использует адрес, деактивированный после выбора', function (): void {
    registerCabinetOrderShowRoute();

    $addresses = cabinetCreateOrderAddresses();
    $component = cabinetCreateOrderComponent($addresses);

    $addresses['dropoff']->update(['is_active' => false]);

    $component->call('estimate')
        ->assertHasErrors(['dropoff'])
        ->assertSee(__('ui.create_order.address_not_selected'));

    $component->call('create')->assertHasErrors(['dropoff']);

    $this->assertDatabaseCount('delivery_orders', 0);
    $this->assertDatabaseCount('idempotency_keys', 0);
});

it('сбрасывает выбор при изменении строки поиска', function (): void {
    $addresses = cabinetCreateOrderAddresses();
    $component = cabinetCreateOrderComponent($addresses);

    $component->call('estimate')->assertHasNoErrors();

    // Пользователь дописал строку: прежний адрес больше не действует.
    $component->set('pickupQuery', 'Тверская, 9 — Тверской')
        ->call('estimate')
        ->assertHasErrors(['pickup']);
});

it('сбрасывает прежний выбор, если подсказка стала недоступна', function (): void {
    $addresses = cabinetCreateOrderAddresses();
    $component = cabinetCreateOrderComponent($addresses);

    expect($component->get('pickupAddressId'))->toBe($addresses['pickup']->id);

    // Устаревшая подсказка: записи уже нет, прежний выбор не должен остаться.
    $component->call('selectPickup', $addresses['pickup']->id + 1000)
        ->assertHasErrors(['pickup'])
        ->assertSet('pickupAddressId', null)
        ->call('estimate')
        ->assertHasErrors(['pickup']);

    // Второй адрес не пострадал: сброс точечный.
    expect($component->get('dropoffAddressId'))->toBe($addresses['dropoff']->id);
});

it('не перезаписывает свежие подсказки устаревшим ответом поиска', function (): void {
    cabinetCreateOrderAddresses();

    $component = Livewire::test(CreateOrder::class);

    $component->set('pickupQuery', 'твер');

    $fresh = $component->get('pickupSuggestions');

    expect($fresh)->toHaveCount(2);

    // Ответ на прошлый терм приходит позже свежего — он отбрасывается.
    // Livewire запрещает вызывать lifecycle-хуки напрямую, поэтому зовём его
    // у экземпляра компонента: проверяется сама защита от устаревшего ответа.
    $component->instance()->updatedPickupQuery('тв');

    expect($component->get('pickupSuggestions'))->toBe($fresh);
});

it('очищает выбранный адрес вместе с подсказками и ошибкой поля', function (): void {
    $addresses = cabinetCreateOrderAddresses();
    $component = cabinetCreateOrderComponent($addresses);

    $component->call('clearPickup')
        ->assertSet('pickupAddressId', null)
        ->assertSet('pickupQuery', '')
        ->assertSet('pickupSuggestions', []);

    $component->call('estimate')->assertHasErrors(['pickup']);

    // Очистка снимает ошибку поля: ввод можно начать заново.
    $component->call('clearPickup')->assertHasNoErrors('pickup');
});
