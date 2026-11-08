<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\PushMessage;
use App\Models\RecipientContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Служебный API журнала, повтора и симулятора push (FR-NTF-254…276)
|--------------------------------------------------------------------------
|
| Проверяются сервисный токен, формат problem+json, фильтры и курсорная
| пагинация, коды повтора и отсутствие персональных данных в ответах.
|
*/

uses(RefreshDatabase::class);

const API_TOKEN = 'test-service-token';

/**
 * @return array<string, string>
 */
function notificationsApiHeaders(): array
{
    return ['Authorization' => 'Bearer '.API_TOKEN];
}

/**
 * Фиксирует момент создания строки журнала в UTC: часовой пояс приложения
 * в контейнере — Europe/Moscow, а колонки хранятся в timestamptz.
 */
function apiFixCreatedAt(int $notificationId, string $moment): void
{
    DB::table('notifications')->where('id', $notificationId)->update([
        'created_at' => $moment,
        'updated_at' => $moment,
    ]);
}

beforeEach(function (): void {
    config([
        'notifications.api.token' => API_TOKEN,
        'notifications.api.page_limit' => 2,
        'notifications.api.max_page_limit' => 10,
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('отвечает 401 без сервисного токена и не раскрывает деталей', function (): void {
    $response = $this->getJson('/api/v1/notifications');

    $response->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'code' => 'INVALID_SERVICE_TOKEN',
            'status' => 401,
        ]);

    expect((string) $response->getContent())->not->toContain(API_TOKEN);
});

it('отвечает 401 на неверный сервисный токен', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
        ->getJson('/api/v1/notifications')
        ->assertStatus(401)
        ->assertJson(['code' => 'INVALID_SERVICE_TOKEN']);
});

it('отдаёт журнал постранично курсором', function (): void {
    Notification::factory()->count(5)->create();

    $first = $this->withHeaders(notificationsApiHeaders())->getJson('/api/v1/notifications');
    $first->assertOk()->assertJsonCount(2, 'data');

    $cursor = $first->json('next_cursor');
    expect($cursor)->toBeString();

    $second = $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/notifications?cursor='.urlencode((string) $cursor));
    $second->assertOk()->assertJsonCount(2, 'data');

    $third = $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/notifications?cursor='.urlencode((string) $second->json('next_cursor')));
    $third->assertOk()->assertJsonCount(1, 'data');

    expect($third->json('next_cursor'))->toBeNull();

    $firstIds = array_column((array) $first->json('data'), 'id');
    $secondIds = array_column((array) $second->json('data'), 'id');

    expect((int) ($firstIds[0] ?? 0))->toBeGreaterThan((int) ($secondIds[0] ?? 0));
});

it('фильтрует журнал по статусу, каналу и периоду', function (): void {
    $failed = Notification::factory()->create(['status' => NotificationStatus::Failed->value]);
    apiFixCreatedAt($failed->id, '2026-11-06 10:00:00+00:00');

    $created = Notification::factory()->create();
    apiFixCreatedAt($created->id, '2026-11-06 11:00:00+00:00');

    $push = Notification::factory()->push()->sent()->create();
    apiFixCreatedAt($push->id, '2026-11-06 12:00:00+00:00');

    $byStatus = $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/notifications?status=failed&channel=email');
    $byStatus->assertOk()->assertJsonCount(1, 'data');
    expect($byStatus->json('data.0.id'))->toBe($failed->id);

    $byChannel = $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/notifications?channel=push');
    $byChannel->assertOk()->assertJsonCount(1, 'data');
    expect($byChannel->json('data.0.id'))->toBe($push->id);

    $byPeriod = $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/notifications?from=2026-11-06T10:30:00Z&to=2026-11-06T11:30:00Z');
    $byPeriod->assertOk()->assertJsonCount(1, 'data');
    expect($byPeriod->json('data.0.id'))->toBe($created->id);
});

it('не отдаёт персональные данные в журнале', function (): void {
    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    Notification::factory()->create(['recipient_id' => 7]);

    $response = $this->withHeaders(notificationsApiHeaders())->getJson('/api/v1/notifications');
    $response->assertOk();

    expect((string) $response->getContent())->not->toContain('sender@example.test');

    $response->assertJsonMissingPath('data.0.email')
        ->assertJsonMissingPath('data.0.phone');
});

it('отклоняет некорректный курсор как problem+json 422', function (): void {
    $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/notifications?cursor=not-a-cursor')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'VALIDATION_FAILED', 'status' => 422]);
});

it('принимает повтор необработанного уведомления и переводит его в error', function (): void {
    config(['notifications.sender.backoff_seconds' => [300]]);

    $notification = Notification::factory()->push()->create([
        'status' => NotificationStatus::Failed->value,
        'attempts' => 1,
    ]);

    NotificationAttempt::query()->insert([
        'notification_id' => $notification->id,
        'attempt' => 1,
        'status' => 'failed',
        'error_class' => 'business',
        'error_code' => 'NOTIFICATION_RECIPIENT_UNKNOWN',
        'error_message' => null,
        'provider_code' => null,
        'created_at' => now()->utc()->subDay()->format('Y-m-d H:i:sP'),
    ]);

    $response = $this->withHeaders(notificationsApiHeaders())
        ->postJson('/api/v1/notifications/'.$notification->id.'/retry');

    $response->assertStatus(202)
        ->assertJson(['id' => $notification->id, 'status' => 'error']);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->attempts)->toBe(1)
        ->and(NotificationAttempt::query()->where('notification_id', $notification->id)->count())->toBe(1);

    // Повтор идемпотентен: второй вызов не создаёт строку и не меняет счётчик.
    $this->withHeaders(notificationsApiHeaders())
        ->postJson('/api/v1/notifications/'.$notification->id.'/retry')
        ->assertStatus(202)
        ->assertJson(['id' => $notification->id, 'status' => 'error']);

    expect(NotificationAttempt::query()->where('notification_id', $notification->id)->count())->toBe(1);

    // Ручной повтор делает запись готовой к отправке сразу, без паузы backoff.
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(2);
});

it('отклоняет повтор отправленного уведомления с кодом 409', function (): void {
    $notification = Notification::factory()->sent()->create();

    $this->withHeaders(notificationsApiHeaders())
        ->postJson('/api/v1/notifications/'.$notification->id.'/retry')
        ->assertStatus(409)
        ->assertJson(['code' => 'NOTIFICATION_ALREADY_SENT', 'status' => 409]);
});

it('отвечает 404 на повтор отсутствующего уведомления', function (): void {
    $this->withHeaders(notificationsApiHeaders())
        ->postJson('/api/v1/notifications/999999/retry')
        ->assertStatus(404)
        ->assertJson(['code' => 'NOT_FOUND', 'status' => 404]);
});

it('отдаёт журнал push-симулятора без адресов и персональных данных', function (): void {
    PushMessage::factory()->count(3)->create();

    $response = $this->withHeaders(notificationsApiHeaders())->getJson('/api/v1/push-messages');

    $response->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'notification_id', 'template', 'body', 'sent_at']], 'next_cursor'])
        ->assertJsonMissingPath('data.0.recipient_id');

    expect($response->json('next_cursor'))->toBeString();

    $this->withHeaders(notificationsApiHeaders())
        ->getJson('/api/v1/push-messages?limit=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
