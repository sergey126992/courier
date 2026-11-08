<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Экран настроек уведомлений: страница кабинета (ЧТЗ 9.4, пункты 5, 6, 8, 9)
|--------------------------------------------------------------------------
|
| FR-UI-401…411: экран доступен подтверждённому пользователю любой роли,
| вход — карточка-ссылка в профиле, при недоступном сервисе страница
| открывается с предупреждением. В разметке нет адреса сервиса, токена,
| `chat_id`, горизонтальной прокрутки и литеральных цветов.
|
*/

function notificationScreenServiceSettings(): void
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
 * @param list<string> $channels
 * @return list<array<string, mixed>>
 */
function notificationScreenRows(array $channels = ['email']): array
{
    return [
        [
            'event_type' => 'order.created',
            'channels' => $channels,
            'source' => 'user',
            'quiet_hours' => null,
            'locale' => 'ru',
        ],
    ];
}

/**
 * @param list<array<string, mixed>>|null $rows
 */
function notificationScreenFake(?array $rows = null): void
{
    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response([
            'user_id' => 1,
            'preferences' => $rows ?? notificationScreenRows(),
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
}

it('закрывает экран настроек гостю и неподтверждённой почте', function (): void {
    $this->get('/account/notifications')->assertRedirect('/login');

    $unverified = User::factory()->sender()->unverified()->create();

    $this->actingAs($unverified)
        ->get('/account/notifications')
        ->assertRedirect(route('verification.notice'));
});

it('открывает экран подтверждённому пользователю и показывает блоки', function (): void {
    notificationScreenServiceSettings();
    notificationScreenFake();

    $this->actingAs(User::factory()->sender()->create())
        ->get('/account/notifications')
        ->assertOk()
        ->assertSee(__('ui.notifications.title'))
        ->assertSee(__('ui.notifications.matrix.title'))
        ->assertSee(__('ui.notifications.events.order_created'))
        ->assertSee(__('ui.notifications.channels.email'))
        ->assertSee(__('ui.notifications.quiet.title'))
        ->assertSee(__('ui.notifications.telegram.title'))
        ->assertSee(__('ui.notifications.telegram.status.verified'))
        ->assertSee(__('ui.actions.save'));
});

it('показывает диспетчеру пустой набор типов событий', function (): void {
    notificationScreenServiceSettings();
    notificationScreenFake([]);

    $this->actingAs(User::factory()->dispatcher()->create())
        ->get('/account/notifications')
        ->assertOk()
        ->assertSee(__('ui.notifications.matrix.empty'))
        ->assertSee(__('ui.notifications.telegram.title'));
});

it('открывает экран с предупреждением, когда сервис недоступен', function (): void {
    notificationScreenServiceSettings();

    Http::fake(['*' => static fn (): mixed => throw new ConnectionException('down')]);

    $this->actingAs(User::factory()->courier()->create())
        ->get('/account/notifications')
        ->assertOk()
        ->assertSee(__('ui.notifications.service_unavailable.title'))
        ->assertSee(__('ui.actions.refresh'))
        ->assertDontSee(__('ui.actions.save'));
});

it('не показывает адрес сервиса, токен и chat_id в разметке', function (): void {
    notificationScreenServiceSettings();
    notificationScreenFake();

    $content = (string) $this->actingAs(User::factory()->sender()->create())
        ->get('/account/notifications')
        ->assertOk()
        ->getContent();

    // Литеральные цвета проверяет UiThemeGuardTest по исходникам шаблонов:
    // сюда попадают служебные стили Livewire, а не разметка экрана.
    expect($content)->not->toContain('notifications.test');
    expect($content)->not->toContain('test-token');
    expect($content)->not->toContain('chat_id');
    expect($content)->not->toContain('-100500');
    expect($content)->not->toContain('overflow-x-');
});

it('даёт вход на экран из карточки профиля', function (): void {
    notificationScreenServiceSettings();
    notificationScreenFake();

    $this->actingAs(User::factory()->sender()->create())
        ->get('/account')
        ->assertOk()
        ->assertSee(route('account.notifications'), false)
        ->assertSee(__('ui.notifications.entry.link'));
});
