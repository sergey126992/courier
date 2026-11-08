<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Административные страницы уведомлений (ЧТЗ 8.2, FR-NTF-405)
|--------------------------------------------------------------------------
|
| Доступ только администратору, единственный источник данных — служебный
| API сервиса оповещений, подпись источника «сервис оповещений», повтор из
| интерфейса и `503` при недоступном сервисе. Локального журнала и
| индикатора режима нет (FR-NTF-403). Сеть не используется: прокси-вызовы
| подменяются `Http::fake()`.
|
*/

function notificationsAdminServiceSettings(): void
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
function notificationsAdminServiceRow(array $overrides = []): array
{
    return array_merge([
        'id' => 77,
        'channel' => 'email',
        'status' => 'failed',
        'attempts' => 4,
        'error_code' => 'SMTP_ERROR',
        'event_type' => 'order.created',
        'created_at' => '2026-05-01T09:00:00+00:00',
    ], $overrides);
}

it('закрывает админ-страницы уведомлений гостю редиректом на вход', function (): void {
    $this->get('/notifications/failed')->assertRedirect('/login');
    $this->get('/notifications/push-simulator')->assertRedirect('/login');
});

it('показывает журнал сервиса администратору и называет источник', function (): void {
    notificationsAdminServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/notifications*' => Http::response([
            'data' => [notificationsAdminServiceRow()],
            'next_cursor' => null,
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/failed')
        ->assertOk()
        ->assertSee(__('notifications.admin.journal_title'))
        ->assertSee(__('notifications.admin.source'))
        ->assertSee('SMTP_ERROR')
        // Индикатора активного режима больше нет (FR-NTF-405).
        ->assertDontSee('Режим доставки');
});

it('запрещает админ-страницы уведомлений остальным ролям', function (): void {
    $users = [
        User::factory()->sender()->create(),
        User::factory()->courier()->create(),
        User::factory()->dispatcher()->create(),
    ];

    foreach ($users as $user) {
        $this->actingAs($user)->get('/notifications/failed')->assertForbidden();
        $this->actingAs($user)->get('/notifications/push-simulator')->assertForbidden();
        $this->actingAs($user)->post('/notifications/1/retry')->assertForbidden();
    }
});

it('берёт журнал сервиса и передаёт сервисный токен', function (): void {
    notificationsAdminServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/notifications*' => Http::response([
            'data' => [notificationsAdminServiceRow()],
            'next_cursor' => null,
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/failed')
        ->assertOk()
        ->assertSee(__('notifications.admin.source'))
        ->assertSee('SMTP_ERROR');

    Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'http://notifications.test/api/v1/notifications')
        && $request->hasHeader('Authorization'));
});

it('показывает понятное состояние страницы, когда сервис недоступен', function (): void {
    notificationsAdminServiceSettings();

    Http::fake([
        '*' => static fn (): mixed => throw new ConnectionException('down'),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/failed')
        ->assertOk()
        ->assertSee(__('notifications.admin.service_unavailable'));
});

it('повторяет уведомление через API сервиса и пробрасывает ответы', function (): void {
    notificationsAdminServiceSettings();

    $admin = User::factory()->admin()->create();

    Http::fake(['*/api/v1/notifications/5/retry' => Http::response(['id' => 5], 202)]);
    $this->actingAs($admin)->post('/notifications/5/retry')->assertStatus(202);

    Http::fake(['*/api/v1/notifications/6/retry' => Http::response(['code' => 'NOT_FOUND'], 404)]);
    $this->actingAs($admin)->post('/notifications/6/retry')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND');

    Http::fake(['*/api/v1/notifications/7/retry' => Http::response(['code' => 'NOTIFICATION_ALREADY_SENT'], 409)]);
    $this->actingAs($admin)->post('/notifications/7/retry')
        ->assertStatus(409)
        ->assertJsonPath('code', 'NOTIFICATION_ALREADY_SENT');
});

it('отдаёт 503 без внутреннего текста, когда сервис не отвечает на повтор', function (): void {
    notificationsAdminServiceSettings();

    Http::fake([
        '*' => static fn (): mixed => throw new ConnectionException('down'),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/notifications/5/retry')
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOTIFICATION_SERVICE_UNAVAILABLE');
});

it('показывает симулятор push из сервиса без персональных данных', function (): void {
    notificationsAdminServiceSettings();

    Http::fake([
        'http://notifications.test/api/v1/push-messages*' => Http::response([
            'data' => [[
                'id' => 3,
                'notification_id' => 42,
                'template' => 'order_created_push',
                'body' => 'Заказ CRG-2026-000042 создан',
                'sent_at' => '2026-05-01T09:00:00+00:00',
            ]],
            'next_cursor' => null,
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/push-simulator')
        ->assertOk()
        ->assertSee(__('notifications.admin.simulator_title'))
        ->assertSee(__('notifications.admin.source'))
        ->assertSee('Заказ CRG-2026-000042 создан')
        ->assertSee('42');
});

it('показывает пустой симулятор push, когда сервис не ответил', function (): void {
    notificationsAdminServiceSettings();

    Http::fake([
        '*' => static fn (): mixed => throw new ConnectionException('down'),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/notifications/push-simulator')
        ->assertOk()
        ->assertSee(__('notifications.admin.service_unavailable'));
});
