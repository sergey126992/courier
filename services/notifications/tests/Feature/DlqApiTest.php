<?php

declare(strict_types=1);

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationStatus;
use App\Models\DlqEntry;
use App\Models\Notification;
use App\Models\RecipientContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API очереди необработанных (ЧТЗ 9.2, FR-NTF-505…512)
|--------------------------------------------------------------------------
|
| Список с фильтрами и курсорной пагинацией без персональных данных,
| повтор с ответами `202`/`404`/`409` и идемпотентностью, `401` без токена
| и единый формат `application/problem+json`. Сети нет: база подменена
| транзакцией теста, время фиксируется `Carbon::setTestNow`.
|
*/

uses(RefreshDatabase::class);

const DLQ_API_TOKEN = 'test-service-token';

/**
 * @return array<string, string>
 */
function dlqApiHeaders(): array
{
    return ['Authorization' => 'Bearer '.DLQ_API_TOKEN];
}

/**
 * Запись DLQ с заданным моментом отказа.
 *
 * @param array<string, mixed> $overrides переопределяющие поля записи
 */
function dlqApiEntry(string $failedAt, array $overrides = []): DlqEntry
{
    $notification = Notification::factory()->create();

    return DlqEntry::factory()->create(array_merge([
        'notification_id' => $notification->id,
        'channel' => (string) $notification->channel,
        'event_id' => $notification->event_id,
        'event_type' => (string) $notification->event_type,
        'order_id' => $notification->order_id,
        'recipient_id' => (int) $notification->recipient_id,
        'recipient_role' => (string) $notification->recipient_role,
        'failed_at' => $failedAt,
    ], $overrides));
}

beforeEach(function (): void {
    config([
        'notifications.api.token' => DLQ_API_TOKEN,
        'notifications.dlq.page_limit' => 2,
        'notifications.dlq.max_page_limit' => 10,
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('отвечает 401 без сервисного токена на список и повтор', function (): void {
    $this->getJson('/api/v1/dlq')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'INVALID_SERVICE_TOKEN', 'status' => 401]);

    $this->postJson('/api/v1/dlq/1/replay')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'INVALID_SERVICE_TOKEN']);
});

it('отдаёт список постранично в порядке времени отказа', function (): void {
    $first = dlqApiEntry('2026-11-06 10:00:00+00:00');
    $second = dlqApiEntry('2026-11-06 11:00:00+00:00');
    $third = dlqApiEntry('2026-11-06 12:00:00+00:00');

    $page = $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq');

    $page->assertOk()->assertJsonCount(2, 'data');
    expect($page->json('data.0.id'))->toBe($third->id)
        ->and($page->json('data.1.id'))->toBe($second->id);

    $cursor = $page->json('next_cursor');
    expect($cursor)->toBeString();

    $next = $this->withHeaders(dlqApiHeaders())
        ->getJson('/api/v1/dlq?cursor='.urlencode((string) $cursor));

    $next->assertOk()->assertJsonCount(1, 'data');
    expect($next->json('data.0.id'))->toBe($first->id)
        ->and($next->json('next_cursor'))->toBeNull();
});

it('фильтрует список по каналу, состоянию и периоду', function (): void {
    $old = dlqApiEntry('2026-11-01 10:00:00+00:00', ['channel' => 'email']);
    $push = dlqApiEntry('2026-11-05 10:00:00+00:00', ['channel' => 'push']);
    $resolved = dlqApiEntry('2026-11-06 10:00:00+00:00', ['channel' => 'email']);
    $resolved->forceFill(['state' => DlqState::Resolved, 'resolved_at' => '2026-11-06 11:00:00+00:00'])->save();

    $byChannel = $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq?channel=push');
    $byChannel->assertOk()->assertJsonCount(1, 'data');
    expect($byChannel->json('data.0.id'))->toBe($push->id);

    $byState = $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq?state=resolved');
    $byState->assertOk()->assertJsonCount(1, 'data');
    expect($byState->json('data.0.id'))->toBe($resolved->id);

    $byPeriod = $this->withHeaders(dlqApiHeaders())
        ->getJson('/api/v1/dlq?from=2026-11-02T00:00:00Z&to=2026-11-05T23:59:59Z');
    $byPeriod->assertOk()->assertJsonCount(1, 'data');
    expect($byPeriod->json('data.0.id'))->toBe($push->id)
        ->and($old->id)->not->toBe($push->id);
});

it('отвергает некорректные фильтры и размер порции', function (): void {
    $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq?channel=sms')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq?limit=11')
        ->assertStatus(422);

    $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq?cursor=broken')
        ->assertStatus(422)
        ->assertJson(['code' => 'VALIDATION_FAILED']);
});

it('не отдаёт персональные данные в списке', function (): void {
    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    $entry = dlqApiEntry('2026-11-06 10:00:00+00:00', ['recipient_id' => 7]);

    $response = $this->withHeaders(dlqApiHeaders())->getJson('/api/v1/dlq');

    $response->assertOk();
    $row = (array) $response->json('data.0');

    expect(array_keys($row))->toBe([
        'id',
        'notification_id',
        'event_id',
        'event_type',
        'order_id',
        'channel',
        'state',
        'error_class',
        'error_code',
        'attempts',
        'replay_count',
        'failed_at',
    ])->and($row['id'])->toBe($entry->id);

    $content = (string) $response->getContent();

    expect($content)->not->toContain('sender@example.test')
        ->and($content)->not->toContain('recipient_id')
        ->and($content)->not->toContain('chat_id');
});

it('ставит открытую запись на повтор и возвращает 202', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $notification = Notification::factory()->create(['status' => NotificationStatus::Failed->value]);
    $entry = DlqEntry::factory()->create([
        'notification_id' => $notification->id,
        'channel' => (string) $notification->channel,
        'failed_at' => '2026-11-06 10:00:00+00:00',
    ]);

    $response = $this->withHeaders(dlqApiHeaders())->postJson('/api/v1/dlq/'.$entry->id.'/replay');

    $response->assertStatus(202)->assertJson([
        'data' => [
            'id' => $entry->id,
            'notification_id' => $notification->id,
            'state' => 'replayed',
            'replay_count' => 1,
        ],
    ]);

    expect(DB::table('notifications')->where('id', $notification->id)->value('status'))
        ->toBe(NotificationStatus::Error->value)
        ->and(DB::table('dlq_entries')->count())->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(1)
        ->and(DlqEntry::query()->find($entry->id)?->state)->toBe(DlqState::Replayed);
});

it('отклоняет повтор уже разобранной или повторяемой записи кодом 409', function (): void {
    $replayed = dlqApiEntry('2026-11-06 10:00:00+00:00', ['state' => DlqState::Replayed->value, 'replay_count' => 1]);
    $resolved = dlqApiEntry('2026-11-06 11:00:00+00:00', ['state' => DlqState::Resolved->value, 'resolved_at' => '2026-11-06 12:00:00+00:00']);

    foreach ([$replayed, $resolved] as $entry) {
        $this->withHeaders(dlqApiHeaders())->postJson('/api/v1/dlq/'.$entry->id.'/replay')
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'code' => 'DLQ_ENTRY_ALREADY_RESOLVED',
                'status' => 409,
            ]);
    }

    expect(DlqEntry::query()->find($replayed->id)?->replay_count)->toBe(1);
});

it('отвечает 404 на неизвестную запись и на очищенную строку журнала', function (): void {
    $this->withHeaders(dlqApiHeaders())->postJson('/api/v1/dlq/999/replay')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'DLQ_ENTRY_NOT_FOUND', 'status' => 404]);

    $entry = dlqApiEntry('2026-11-06 10:00:00+00:00');
    DB::table('notifications')->where('id', $entry->notification_id)->delete();

    $this->withHeaders(dlqApiHeaders())->postJson('/api/v1/dlq/'.$entry->id.'/replay')
        ->assertStatus(404)
        ->assertJson(['code' => 'DLQ_ENTRY_NOT_FOUND']);
});

it('повтор идемпотентен: второго уведомления и записи не создаёт', function (): void {
    $entry = dlqApiEntry('2026-11-06 10:00:00+00:00');

    $this->withHeaders(dlqApiHeaders())->postJson('/api/v1/dlq/'.$entry->id.'/replay')->assertStatus(202);
    $this->withHeaders(dlqApiHeaders())->postJson('/api/v1/dlq/'.$entry->id.'/replay')->assertStatus(409);

    expect(DB::table('notifications')->count())->toBe(1)
        ->and(DB::table('dlq_entries')->count())->toBe(1);
});
