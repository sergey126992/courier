<?php

declare(strict_types=1);

use App\Livewire\Admin\NotificationServicePage;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Страница «Сервис оповещений»: DLQ (ЧТЗ 8.2, 9.2 п. 6, FR-NTF-511, FR-NTF-512)
|--------------------------------------------------------------------------
|
| Доступ только администратору, диспетчер получает отказ. Данные вкладки
| DLQ приходят из сервиса, повтор идёт через прокси и пробрасывает
| problem+json с кодами DLQ_ENTRY_*, недоступность сервиса даёт понятное
| состояние, а разметка строится на существующих компонентах и `scroll-area`.
| Сеть не используется: прокси-вызовы подменяются `Http::fake()`.
|
*/

function dlqPageServiceSettings(): void
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
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function dlqPageRow(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'notification_id' => 42,
        'event_id' => '0b0f0f9e-0000-4000-8000-000000000000',
        'event_type' => 'order.delivered',
        'order_id' => 101,
        'channel' => 'push',
        'state' => 'open',
        'error_class' => 'business',
        'error_code' => 'NOTIFICATION_DEVICE_UNREGISTERED',
        'attempts' => 5,
        'replay_count' => 0,
        'failed_at' => '2026-11-07T10:00:00+03:00',
    ], $overrides);
}

it('закрывает страницу и прокси-повтор гостю редиректом на вход', function (): void {
    $this->get('/notifications/service')->assertRedirect('/login');
    $this->post('/notifications/service/dlq/7/replay')->assertRedirect('/login');
});

it('показывает вкладку DLQ администратору на компонентах и scroll-area', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/dlq*' => Http::response([
            'data' => [dlqPageRow()],
            'next_cursor' => null,
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/service')
        ->assertOk()
        ->assertSee(__('notifications.admin.service_title'))
        ->assertSee(__('notifications.admin.dlq.tab'))
        ->assertSee(__('notifications.admin.metrics.tab'))
        ->assertSee('NOTIFICATION_DEVICE_UNREGISTERED')
        ->assertSee('role="tablist"', false)
        ->assertSee('scroll-area', false)
        ->assertSee('notification-row', false)
        ->assertSee('badge-error', false)
        ->assertDontSee('sender@example.test');

    Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'http://notifications.test/api/v1/dlq')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('показывает пустое состояние, когда очередь пуста', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [], 'next_cursor' => null]),
        'http://notifications.test/metrics' => Http::response('', 200, ['Content-Type' => 'text/plain; version=0.0.4']),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/service')
        ->assertOk()
        ->assertSee(__('notifications.admin.dlq.empty_title'))
        ->assertSee(__('notifications.admin.metrics.empty_title'));
});

it('показывает понятное состояние, когда сервис недоступен', function (): void {
    dlqPageServiceSettings();

    Http::fake(['*' => static fn (): mixed => throw new ConnectionException('down')]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/service')
        ->assertOk()
        ->assertSee(__('notifications.admin.service_unavailable'));
});

it('запрещает страницу и повтор диспетчеру и остальным ролям', function (): void {
    $users = [
        User::factory()->dispatcher()->create(),
        User::factory()->sender()->create(),
        User::factory()->courier()->create(),
    ];

    foreach ($users as $user) {
        $this->actingAs($user)->get('/notifications/service')->assertForbidden();
        $this->actingAs($user)->post('/notifications/service/dlq/7/replay')->assertForbidden();
    }
});

it('пробрасывает 202 и коды DLQ из прокси-повтора', function (): void {
    dlqPageServiceSettings();
    $admin = User::factory()->admin()->create();

    Http::fake(['*/api/v1/dlq/7/replay' => Http::response([
        'data' => ['id' => 7, 'notification_id' => 42, 'state' => 'replayed', 'replay_count' => 1],
    ], 202)]);

    $this->actingAs($admin)->post('/notifications/service/dlq/7/replay')
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted', 'id' => 7]);

    Http::fake(['*/api/v1/dlq/8/replay' => Http::response(['code' => 'DLQ_ENTRY_NOT_FOUND'], 404)]);
    $this->actingAs($admin)->post('/notifications/service/dlq/8/replay')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'DLQ_ENTRY_NOT_FOUND');

    Http::fake(['*/api/v1/dlq/9/replay' => Http::response(['code' => 'DLQ_ENTRY_ALREADY_RESOLVED'], 409)]);
    $this->actingAs($admin)->post('/notifications/service/dlq/9/replay')
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'DLQ_ENTRY_ALREADY_RESOLVED');
});

it('отдаёт 503 без внутреннего текста, когда сервис не ответил на повтор', function (): void {
    dlqPageServiceSettings();

    Http::fake(['*' => static fn (): mixed => throw new ConnectionException('secret internal detail')]);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post('/notifications/service/dlq/7/replay');

    $response->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOTIFICATION_SERVICE_UNAVAILABLE');

    expect((string) $response->getContent())->not->toContain('secret internal detail');
});

it('повторяет запись из интерфейса и объявляет результат', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        '*/api/v1/dlq/7/replay' => Http::response([
            'data' => ['id' => 7, 'notification_id' => 42, 'state' => 'replayed', 'replay_count' => 1],
        ], 202),
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [dlqPageRow()], 'next_cursor' => null]),
    ]);

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(NotificationServicePage::class)
        ->call('replay', 7)
        ->assertSet('message', __('notifications.admin.dlq.replay_result.accepted'))
        ->assertSet('messageVariant', 'success');
});

it('показывает отказ повтора из интерфейса', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        '*/api/v1/dlq/7/replay' => Http::response(['code' => 'DLQ_ENTRY_ALREADY_RESOLVED'], 409),
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [], 'next_cursor' => null]),
    ]);

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(NotificationServicePage::class)
        ->call('replay', 7)
        ->assertSet('message', __('notifications.admin.dlq.replay_result.conflict'))
        ->assertSet('messageVariant', 'warning');
});

it('показывает вкладку метрик со снимком значений сервиса', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [], 'next_cursor' => null]),
        'http://notifications.test/metrics' => Http::response(
            "# TYPE notifications_sent_total counter\n"
            ."notifications_sent_total{channel=\"email\"} 42\n"
            ."notifications_dlq_depth{channel=\"push\",state=\"open\"} 3\n"
            ."notifications_consumer_lag_seconds{stream=\"events.orders\"} 12\n"
            ."notifications_consumer_pending{stream=\"events.orders\"} 4\n"
            ."notifications_outbox_oldest_unpublished_seconds 9\n",
            200,
            ['Content-Type' => 'text/plain; version=0.0.4'],
        ),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/service')
        ->assertOk()
        ->assertSee(__('notifications.admin.metrics.tab'))
        ->assertSee(__('notifications.admin.metrics.counters_title'))
        ->assertSee(__('notifications.admin.metrics.dlq_title'))
        ->assertSee(__('notifications.admin.metrics.consumer_title'))
        ->assertSee(__('notifications.admin.metrics.outbox_service'))
        ->assertSee('data-list', false)
        ->assertSee('events.orders')
        ->assertSee('42')
        ->assertDontSee(__('notifications.admin.metrics.empty_title'));
});

it('показывает недоступность метрик отдельным предупреждением', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [], 'next_cursor' => null]),
        'http://notifications.test/metrics' => static function (): mixed {
            throw new ConnectionException('metrics down');
        },
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/service')
        ->assertOk()
        ->assertSee(__('notifications.admin.service_unavailable'))
        ->assertDontSee(__('notifications.admin.metrics.counters_title'));
});

it('обновляет снимок метрик по кнопке', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [], 'next_cursor' => null]),
        'http://notifications.test/metrics' => Http::response(
            "notifications_outbox_oldest_unpublished_seconds 5\n",
            200,
            ['Content-Type' => 'text/plain; version=0.0.4'],
        ),
    ]);

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(NotificationServicePage::class)
        ->call('refreshMetrics')
        ->assertSet('metricsFailed', false)
        ->assertSee(__('notifications.admin.metrics.counters_title'));
});

it('передаёт фильтры канала и состояния в сервис', function (): void {
    dlqPageServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/dlq*' => Http::response(['data' => [], 'next_cursor' => null]),
    ]);

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(NotificationServicePage::class)
        ->set('channel', 'push')
        ->set('state', 'open')
        ->call('applyFilters')
        ->assertSet('channel', 'push')
        ->assertSet('state', 'open');

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'channel=push')
        && str_contains($request->url(), 'state=open'));
});
