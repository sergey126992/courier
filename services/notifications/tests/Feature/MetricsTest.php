<?php

declare(strict_types=1);

use App\Metrics\NotificationMetrics;
use App\Models\DlqEntry;
use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Models\RecipientContact;
use App\Services\Notifications\NotificationCounters;
use App\Services\Notifications\NotificationPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Finder\SplFileInfo;

/*
|--------------------------------------------------------------------------
| Метрики сервиса (FR-OPS-501…512, ЧТЗ 9.2 п. 5, 9.3 п. 5)
|--------------------------------------------------------------------------
|
| `/metrics` закрыт сервисным токеном, отдаёт формат Prometheus 0.0.4,
| читает только кэш и не пересчитывает журнал. Gauge считает команда
| расписания. Сеть и Redis подменяются фейками (ЧТЗ 9.7).
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    config(['notifications.metrics.enabled' => true]);

    $GLOBALS['metricsLogs'] = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $record): void {
        $GLOBALS['metricsLogs']->append($record);
    });
});

afterEach(function (): void {
    unset($GLOBALS['metricsLogs']);
    Carbon::setTestNow();
});

/**
 * Заголовок сервисного токена для `/metrics` (FR-OPS-509).
 *
 * @return array<string, string>
 */
function metricsHeaders(): array
{
    return ['Authorization' => 'Bearer '.((string) config('notifications.api.token'))];
}

/**
 * PHP-файлы каталога рекурсивно.
 *
 * @return list<SplFileInfo>
 */
function metricsTestPhpFiles(string $path): array
{
    if (! File::isDirectory($path)) {
        return [];
    }

    return array_values(array_filter(
        File::allFiles($path),
        static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
    ));
}

it('закрывает /metrics без токена и с неверным токеном', function (): void {
    $this->get('/metrics')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_SERVICE_TOKEN');

    $this->get('/metrics', ['Authorization' => 'Bearer wrong-token'])
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('отдаёт метрики с токеном в формате Prometheus 0.0.4', function (): void {
    $response = $this->get('/metrics', metricsHeaders());

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('text/plain; version=0.0.4; charset=utf-8');

    $body = (string) $response->getContent();

    expect($body)->toContain('# TYPE notifications_sent_total counter')
        ->and($body)->toContain('# TYPE notifications_failed_total counter')
        ->and($body)->toContain('# TYPE notifications_retried_total counter')
        ->and($body)->toContain('# TYPE notifications_send_duration_seconds histogram')
        ->and($body)->toContain('# TYPE notifications_dlq_depth gauge')
        ->and($body)->toContain('# TYPE notifications_consumer_lag_seconds gauge')
        ->and($body)->toContain('# TYPE notifications_consumer_pending gauge')
        ->and($body)->toContain('notifications_consumer_lag_seconds{stream="events.orders.test"} 0')
        ->and($body)->toContain("notifications_outbox_oldest_unpublished_seconds 0\n");
});

it('отвечает 404 в problem+json, когда экспорт метрик выключен', function (): void {
    config(['notifications.metrics.enabled' => false]);

    $this->get('/metrics', metricsHeaders())
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('читает счётчики из кэша и не пересчитывает базу', function (): void {
    $counters = app(NotificationCounters::class);
    $counters->recordSent('email');
    $counters->recordSent('email');
    $counters->recordSent('push');
    $counters->recordFailed('push');
    $counters->recordRetried('push');
    $counters->observeDuration('email', 0.7);

    expect($counters->flushToCache())->toBeTrue();

    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $response = $this->get('/metrics', metricsHeaders());
    $body = (string) $response->getContent();

    $response->assertOk();

    expect($body)->toContain('notifications_sent_total{channel="email"} 2')
        ->and($body)->toContain('notifications_sent_total{channel="push"} 1')
        ->and($body)->toContain('notifications_failed_total{channel="push"} 1')
        ->and($body)->toContain('notifications_retried_total{channel="push"} 1')
        ->and($body)->toContain('notifications_send_duration_seconds_count{channel="email"} 1')
        ->and($queries)->toBe(0);
});

it('накапливает значения при повторном сбросе счётчиков', function (): void {
    $counters = app(NotificationCounters::class);

    $counters->recordSent('email');
    $counters->flushToCache();
    $counters->recordSent('email');
    $counters->flushToCache();

    expect((string) $this->get('/metrics', metricsHeaders())->getContent())
        ->toContain('notifications_sent_total{channel="email"} 2');
});

it('строит гистограмму по границам из конфигурации', function (): void {
    config(['notifications.metrics.histogram_buckets' => [0.25, 1.0, 3.0]]);
    app()->forgetInstance(NotificationCounters::class);

    $counters = app(NotificationCounters::class);
    $counters->observeDuration('push', 0.5);
    $counters->flushToCache();

    $body = (string) $this->get('/metrics', metricsHeaders())->getContent();

    expect($body)->toContain('notifications_send_duration_seconds_bucket{channel="push",le="0.25"} 0')
        ->and($body)->toContain('notifications_send_duration_seconds_bucket{channel="push",le="1"} 1')
        ->and($body)->toContain('notifications_send_duration_seconds_bucket{channel="push",le="3"} 1')
        ->and($body)->toContain('notifications_send_duration_seconds_bucket{channel="push",le="+Inf"} 1')
        ->and($body)->toContain('notifications_send_duration_seconds_sum{channel="push"} 0.5');
});

it('отдаёт частичные данные и пишет degraded, когда кэш недоступен', function (): void {
    config(['notifications.metrics.cache_store' => 'missing-store']);

    $response = $this->get('/metrics', metricsHeaders());

    $response->assertOk();

    expect((string) $response->getContent())->toContain('# TYPE notifications_sent_total counter');

    $degraded = null;

    /** @var list<MessageLogged> $records */
    $records = $GLOBALS['metricsLogs']->getArrayCopy();

    foreach ($records as $record) {
        if (($record->context['error_code'] ?? null) === 'METRICS_UNAVAILABLE') {
            $degraded = $record;

            break;
        }
    }

    expect($degraded)->not->toBeNull();

    /** @var MessageLogged $degraded */
    expect($degraded->context['status'])->toBe('degraded');
});

it('заполняет кэш gauge задачей и отдаёт значения в /metrics', function (): void {
    DlqEntry::factory()->create(['channel' => 'push', 'state' => 'open']);
    DlqEntry::factory()->replayed()->create(['channel' => 'push']);
    DlqEntry::factory()->resolved()->create(['channel' => 'push']);

    // `created_at` не входит в fillable, а приведение Eloquent пишет местное
    // время, поэтому возраст задаётся значением с явным смещением UTC.
    $outbox = OutboxMessage::factory()->create();

    DB::table('outbox_messages')
        ->where('id', $outbox->id)
        ->update(['created_at' => now()->utc()->subSeconds(90)->format('Y-m-d H:i:sP')]);

    bindFakeRedis([
        'xinfo' => [[
            'name' => (string) config('notifications.transport.consumer_group'),
            'pending' => 4,
            'last-delivered-id' => '0-0',
            'lag' => 0,
        ]],
        'xpending' => [['1-0', 'consumer-1', 30000, 1]],
    ]);

    expect(Artisan::call('notifications:metrics-snapshot', ['--json' => true]))->toBe(0);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($payload))->toBe([
        'generated_at',
        'status',
        'counters',
        'durations',
        'dlq_depth',
        'consumer',
        'outbox_oldest_unpublished_seconds',
        'degraded',
    ])
        ->and($payload['status'])->toBe('ok')
        ->and($payload['outbox_oldest_unpublished_seconds'])->toBe(90);

    $body = (string) $this->get('/metrics', metricsHeaders())->getContent();

    expect($body)->toContain('notifications_dlq_depth{channel="push",state="open"} 1')
        ->and($body)->toContain('notifications_dlq_depth{channel="push",state="replayed"} 1')
        ->and($body)->not->toContain('state="resolved"')
        ->and($body)->toContain('notifications_consumer_pending{stream="events.orders.test"} 4')
        ->and($body)->toContain('notifications_consumer_lag_seconds{stream="events.orders.test"} 30')
        ->and($body)->toContain('notifications_outbox_oldest_unpublished_seconds 90');
});

it('регистрирует снимок метрик в расписании один раз', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $commands = array_values(array_filter(
        array_map(static fn (array $event): string => (string) ($event['command'] ?? ''), $events),
        static fn (string $command): bool => str_contains($command, 'notifications:metrics-snapshot'),
    ));

    expect($commands)->toHaveCount(1);
});

it('увеличивает счётчики и гистограмму при отправке и повторе', function (): void {
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);

    Notification::factory()->create(['recipient_id' => 7, 'channel' => 'email', 'status' => 'created', 'attempts' => 0]);
    Notification::factory()->create(['recipient_id' => 7, 'channel' => 'email', 'status' => 'error', 'attempts' => 1]);

    app(NotificationPoller::class)->run(10);

    $snapshot = app(NotificationMetrics::class)->snapshot();

    expect($snapshot->counters['sent']['email'] ?? 0)->toBe(2)
        ->and($snapshot->counters['retried']['email'] ?? 0)->toBe(1)
        ->and($snapshot->durations['email']['count'] ?? 0)->toBe(2)
        ->and($snapshot->status())->toBe('ok');
});

it('не выводит в метрики запрещённых меток', function (): void {
    app(NotificationCounters::class)->recordSent('email');
    app(NotificationCounters::class)->flushToCache();

    $body = (string) $this->get('/metrics', metricsHeaders())->getContent();

    foreach (['user_id', 'order_id', 'recipient_id', 'notification_id', 'correlation_id', 'chat_id'] as $forbidden) {
        expect($body)->not->toContain($forbidden.'=');
    }
});

it('не держит в коде литеральных границ и интервалов метрик', function (): void {
    foreach (metricsTestPhpFiles(app_path('Metrics')) as $file) {
        $contents = (string) $file->getContents();

        expect($contents)
            ->not->toContain('0.1,0.5')
            ->not->toContain('everyMinute')
            ->not->toContain('everyFiveMinutes');
    }

    $console = (string) File::get(base_path('routes/console.php'));

    expect($console)->toContain("config('notifications.metrics.snapshot_cron')");
});
