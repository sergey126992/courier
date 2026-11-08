<?php

declare(strict_types=1);

use App\Livewire\Account\NotificationPreferences;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Экран настроек уведомлений: Livewire-компонент (ЧТЗ 9.4, пункты 3, 5, 6, 9)
|--------------------------------------------------------------------------
|
| FR-UI-402…410: матрица и тихие часы сохраняются одним действием, ошибка
| окна выводится у поля, статус привязки Telegram обновляется без
| перезагрузки. Доступные роли типы событий приходят из сервиса: отправитель
| видит все, курьер — свои, диспетчер — пустой набор. `chat_id`, адрес и
| токен сервиса не появляются ни в свойствах, ни в разметке.
|
*/

function preferencesScreenServiceSettings(): void
{
    config([
        'notifications.service.url' => 'http://notifications.test',
        'notifications.service.token' => 'test-token',
        'notifications.service.timeout_seconds' => 1,
        'notifications.service.retry_times' => 0,
        'notifications.service.retry_sleep_milliseconds' => 0,
    ]);
}

/**
 * @param array<string, mixed>|null $quiet
 * @param list<array<string, mixed>> $overrides
 * @return list<array<string, mixed>>
 */
function preferencesScreenRows(?array $quiet = null, array $overrides = []): array
{
    $rows = [
        ['event_type' => 'order.created', 'channels' => ['email'], 'source' => 'default', 'quiet_hours' => $quiet, 'locale' => 'ru'],
        ['event_type' => 'order.assigned', 'channels' => ['push'], 'source' => 'default', 'quiet_hours' => $quiet, 'locale' => 'ru'],
        ['event_type' => 'order.picked_up', 'channels' => ['email'], 'source' => 'default', 'quiet_hours' => $quiet, 'locale' => 'ru'],
        ['event_type' => 'order.delivered', 'channels' => ['email', 'push'], 'source' => 'default', 'quiet_hours' => $quiet, 'locale' => 'ru'],
        ['event_type' => 'order.failed', 'channels' => ['email'], 'source' => 'default', 'quiet_hours' => $quiet, 'locale' => 'ru'],
        ['event_type' => 'order.cancelled', 'channels' => ['email', 'telegram'], 'source' => 'default', 'quiet_hours' => $quiet, 'locale' => 'ru'],
    ];

    return array_values(array_replace($rows, $overrides));
}

/**
 * Подменяет прокси-вызовы сервиса: предпочтения и привязки.
 *
 * @param list<array<string, mixed>>|null $rows
 * @param list<array<string, mixed>>|null $bindings
 */
function preferencesScreenFake(?array $rows = null, ?array $bindings = null): void
{
    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response([
            'user_id' => 1,
            'preferences' => $rows ?? preferencesScreenRows(),
        ]),
        'http://notifications.test/api/v1/channel-bindings*' => Http::response([
            'bindings' => $bindings ?? [],
        ]),
    ]);
}

it('показывает отправителю матрицу всех типов событий сервиса', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $this->actingAs(User::factory()->sender()->create());

    $component = Livewire::test(NotificationPreferences::class);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'role=sender'));

    expect(array_column($component->get('rows'), 'event_type'))->toBe([
        'order.created',
        'order.assigned',
        'order.picked_up',
        'order.delivered',
        'order.failed',
        'order.cancelled',
    ]);

    $component
        ->assertSee(__('ui.notifications.matrix.title'))
        ->assertSee(__('ui.notifications.events.order_created'))
        ->assertSee(__('ui.notifications.channels.email'))
        ->assertSee(__('ui.notifications.channels.telegram'))
        ->assertSee(__('ui.notifications.quiet.title'))
        ->assertSee(__('ui.notifications.telegram.title'))
        ->assertSee(__('ui.notifications.telegram.status.absent'));
});

it('показывает курьеру только те типы событий, которые вернул сервис', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake([
        ['event_type' => 'order.assigned', 'channels' => ['push'], 'source' => 'default', 'quiet_hours' => null, 'locale' => 'ru'],
        ['event_type' => 'order.cancelled', 'channels' => ['email', 'telegram'], 'source' => 'default', 'quiet_hours' => null, 'locale' => 'ru'],
    ]);

    $this->actingAs(User::factory()->courier()->create());

    $component = Livewire::test(NotificationPreferences::class);

    expect(array_column($component->get('rows'), 'event_type'))
        ->toBe(['order.assigned', 'order.cancelled']);

    expect($component->html())->not->toContain(__('ui.notifications.events.order_created'));

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'role=courier'));
});

it('оставляет диспетчеру пустой набор типов событий и блок тихих часов', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake([]);

    $this->actingAs(User::factory()->dispatcher()->create());

    Livewire::test(NotificationPreferences::class)
        ->assertSet('rows', [])
        ->assertSee(__('ui.notifications.matrix.empty'))
        ->assertSee(__('ui.notifications.quiet.title'))
        ->assertSee(__('ui.notifications.telegram.title'));

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'role=dispatcher'));
});

it('сохраняет матрицу и тихие часы одним действием', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $sender = User::factory()->sender()->create();

    $this->actingAs($sender);

    Livewire::test(NotificationPreferences::class)
        ->set('rows.0.channels.push', true)
        ->set('rows.1.channels.email', true)
        ->set('quietEnabled', true)
        ->set('quietFrom', '22:00')
        ->set('quietTo', '08:00')
        ->set('quietTimezone', 'Europe/Moscow')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', __('ui.notifications.messages.saved'));

    Http::assertSent(static function (Request $request) use ($sender): bool {
        if ($request->method() !== 'PATCH') {
            return false;
        }

        $rows = $request['preferences'];

        return $request['user_id'] === $sender->id
            && $request['role'] === 'sender'
            && $rows[0]['event_type'] === 'order.created'
            && $rows[0]['channels'] === ['email', 'push']
            && $rows[0]['quiet_hours'] === ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow']
            && $rows[1]['channels'] === ['email', 'push'];
    });
});

it('сохраняет выключенные тихие часы как отсутствие окна', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['preferences'][0]['quiet_hours'] === null);
});

it('показывает ошибку у поля, когда начало и конец тихого окна совпадают', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->set('quietEnabled', true)
        ->set('quietFrom', '22:00')
        ->set('quietTo', '22:00')
        ->set('quietTimezone', 'Europe/Moscow')
        ->call('save')
        ->assertHasErrors(['quietTo' => 'different'])
        ->assertSee(__('validation.different', [
            'attribute' => __('validation.attributes.quietTo'),
            'other' => __('validation.attributes.quietFrom'),
        ]));

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PATCH');
});

it('требует время и таймзону, когда тихие часы включены', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->set('quietEnabled', true)
        ->call('save')
        ->assertHasErrors(['quietFrom' => 'required', 'quietTo' => 'required', 'quietTimezone' => 'required']);

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PATCH');
});

it('поднимает окно тихих часов из сохранённой строки сервиса', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake(preferencesScreenRows(['from' => '21:30', 'to' => '07:00', 'timezone' => 'Asia/Novosibirsk']));

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->assertSet('quietEnabled', true)
        ->assertSet('quietFrom', '21:30')
        ->assertSet('quietTo', '07:00')
        ->assertSet('quietTimezone', 'Asia/Novosibirsk');
});

it('открывает экран без формы, когда сервис недоступен', function (): void {
    preferencesScreenServiceSettings();

    Http::fake(['*' => static fn (): mixed => throw new ConnectionException('down')]);

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->assertSet('serviceAvailable', false)
        ->assertSee(__('ui.notifications.service_unavailable.title'))
        ->assertSee(__('ui.actions.refresh'))
        ->assertDontSee(__('ui.notifications.matrix.title'))
        ->assertDontSee(__('ui.actions.save'));
});

it('не отправляет изменения, когда сервис недоступен', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $this->actingAs(User::factory()->sender()->create());

    $component = Livewire::test(NotificationPreferences::class);

    Http::fake(['*' => static fn (): mixed => throw new ConnectionException('down')]);

    $component->call('reload')->assertSet('serviceAvailable', false);
    $component->call('save');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PATCH');
});

it('выпускает ссылку привязки и показывает срок её действия', function (): void {
    preferencesScreenServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response([
            'user_id' => 1,
            'preferences' => [],
        ]),
        'http://notifications.test/api/v1/channel-bindings/telegram/start' => Http::response([
            'deep_link' => 'https://t.me/kargo_bot?start=token',
            'expires_at' => '2026-11-07T10:15:00Z',
            'status' => 'pending',
        ], 201),
        'http://notifications.test/api/v1/channel-bindings*' => Http::response(['bindings' => []]),
    ]);

    $this->actingAs(User::factory()->dispatcher()->create());

    Livewire::test(NotificationPreferences::class)
        ->call('startTelegramBinding')
        ->assertSet('telegramStatus', 'pending')
        ->assertSet('deepLink', 'https://t.me/kargo_bot?start=token')
        ->assertSee(__('ui.notifications.telegram.open_bot'))
        ->assertSee(__('ui.notifications.telegram.status.pending'))
        ->assertSee(__('ui.notifications.telegram.hint'));
});

it('обновляет статус привязки без перезагрузки страницы', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake(null, [[
        'channel' => 'telegram',
        'status' => 'verified',
        'verified_at' => '2026-11-07T10:02:00Z',
        'revoked_at' => null,
    ]]);

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->call('refreshBinding')
        ->assertSet('telegramStatus', 'verified')
        ->assertSee(__('ui.notifications.telegram.status.verified'))
        ->assertSet('message', __('ui.notifications.messages.binding_refreshed'));
});

it('отвязывает Telegram после подтверждения в модальном окне', function (): void {
    preferencesScreenServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings/telegram' => Http::response(null, 204),
        'http://notifications.test/api/v1/preferences*' => Http::response(['user_id' => 1, 'preferences' => []]),
        'http://notifications.test/api/v1/channel-bindings*' => Http::response([
            'bindings' => [['channel' => 'telegram', 'status' => 'verified', 'verified_at' => '2026-11-07T10:02:00Z']],
        ]),
    ]);

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->assertSet('telegramStatus', 'verified')
        ->assertSee(__('ui.notifications.telegram.unbind_title'))
        ->call('unbindTelegram')
        ->assertSet('telegramStatus', 'absent')
        ->assertSet('message', __('ui.notifications.messages.unbound'));

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE');
});

it('показывает безопасное сообщение, когда отвязывать нечего', function (): void {
    preferencesScreenServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings/telegram' => Http::response(
            ['code' => 'TELEGRAM_BINDING_ABSENT'],
            409,
        ),
        'http://notifications.test/api/v1/preferences*' => Http::response(['user_id' => 1, 'preferences' => []]),
        'http://notifications.test/api/v1/channel-bindings*' => Http::response([
            'bindings' => [['channel' => 'telegram', 'status' => 'pending']],
        ]),
    ]);

    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(NotificationPreferences::class)
        ->call('unbindTelegram')
        ->assertSet('message', __('ui.notifications.errors.TELEGRAM_BINDING_ABSENT'));
});

it('не показывает chat_id, адрес и токен сервиса', function (): void {
    preferencesScreenServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response([
            'user_id' => 1,
            'preferences' => [[
                'event_type' => 'order.created',
                'channels' => ['email'],
                'source' => 'user',
                'quiet_hours' => null,
                'locale' => 'ru',
                'chat_id' => '-100500',
            ]],
        ]),
        'http://notifications.test/api/v1/channel-bindings*' => Http::response([
            'bindings' => [[
                'channel' => 'telegram',
                'status' => 'verified',
                'verified_at' => '2026-11-07T10:02:00Z',
                'chat_id' => '-100500',
            ]],
        ]),
    ]);

    $this->actingAs(User::factory()->sender()->create());

    $component = Livewire::test(NotificationPreferences::class);
    $html = $component->html();
    $state = (string) json_encode([
        $component->get('rows'),
        $component->get('telegramStatus'),
        $component->get('telegramVerifiedAt'),
        $component->get('deepLink'),
    ], JSON_THROW_ON_ERROR);

    expect($html)->not->toContain('chat_id');
    expect($html)->not->toContain('-100500');
    expect($html)->not->toContain('notifications.test');
    expect($html)->not->toContain('test-token');
    expect($state)->not->toContain('chat_id');
    expect($state)->not->toContain('-100500');
    expect($state)->not->toContain('notifications.test');
    expect($state)->not->toContain('test-token');
});

it('не задаёт прокрутку и литеральные цвета в разметке', function (): void {
    preferencesScreenServiceSettings();
    preferencesScreenFake();

    $this->actingAs(User::factory()->sender()->create());

    $html = Livewire::test(NotificationPreferences::class)->html();

    // Прокрутка задаётся только `scroll-area` модального окна (FR-UI-144, FR-UI-409).
    expect($html)->not->toContain('overflow-x-');
    expect($html)->not->toContain('overflow-auto');
    expect($html)->not->toContain('overflow-scroll');
    expect($html)->not->toContain('overflow:');
    expect(substr_count($html, 'overflow-y-auto'))->toBe(1);
    expect(preg_match('/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b/', $html))->toBe(0);
    expect(preg_match('/\brgba?\(|\bhsla?\(|\boklch\(/', $html))->toBe(0);
    expect(preg_match('/\b(?:bg|text|border)-(?:slate|gray|zinc|red|green|blue|indigo|purple)-\d{2,3}\b/', $html))->toBe(0);
});
