<?php

declare(strict_types=1);

use App\Domain\Notifications\DlqState;
use App\Models\DlqEntry;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Команды эксплуатации DLQ (ЧТЗ 8.6, 9.2)
|--------------------------------------------------------------------------
|
| `notifications:dlq-list`, `notifications:dlq-replay`, `notifications:dlq-purge`:
| ключи, коды возврата 0/1/2, вывод без персональных данных и регистрация
| очистки в расписании ровно одним событием.
|
*/

uses(RefreshDatabase::class);

/**
 * Запись DLQ с заданным моментом отказа.
 *
 * @param array<string, mixed> $overrides переопределяющие поля записи
 */
function dlqConsoleEntry(string $failedAt, array $overrides = []): DlqEntry
{
    $notification = Notification::factory()->create();

    return DlqEntry::factory()->create(array_merge([
        'notification_id' => $notification->id,
        'channel' => (string) $notification->channel,
        'failed_at' => $failedAt,
    ], $overrides));
}

beforeEach(function (): void {
    config([
        'notifications.dlq.page_limit' => 50,
        'notifications.dlq.max_page_limit' => 200,
        'notifications.dlq.retention_days' => 90,
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('печатает список DLQ и JSON без персональных данных', function (): void {
    dlqConsoleEntry('2026-11-06 10:00:00+00:00', ['channel' => 'email']);
    dlqConsoleEntry('2026-11-06 11:00:00+00:00', ['channel' => 'push']);

    expect(Artisan::call('notifications:dlq-list', ['--json' => true]))->toBe(0);

    $output = trim(Artisan::output());
    /** @var array{count: int, entries: list<array<string, mixed>>} $payload */
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['count'])->toBe(2)
        ->and(array_column($payload['entries'], 'channel'))->toContain('push')
        ->and($output)->not->toContain('@');
});

it('фильтрует список по каналу и состоянию', function (): void {
    dlqConsoleEntry('2026-11-06 10:00:00+00:00', ['channel' => 'email']);
    dlqConsoleEntry('2026-11-06 11:00:00+00:00', ['channel' => 'push']);

    $this->artisan('notifications:dlq-list', ['--channel' => 'push', '--json' => true])
        ->expectsOutputToContain('"count":1')
        ->assertExitCode(0);

    $this->artisan('notifications:dlq-list', ['--state' => 'resolved', '--json' => true])
        ->expectsOutputToContain('"count":0')
        ->assertExitCode(0);
});

it('возвращает 2 на неизвестный канал, состояние и лимит', function (): void {
    $this->artisan('notifications:dlq-list', ['--channel' => 'sms'])->assertExitCode(2);
    $this->artisan('notifications:dlq-list', ['--state' => 'broken'])->assertExitCode(2);
    $this->artisan('notifications:dlq-list', ['--limit' => '0'])->assertExitCode(2);
});

it('повторяет запись командой и возвращает 0', function (): void {
    $entry = dlqConsoleEntry('2026-11-06 10:00:00+00:00');

    $this->artisan('notifications:dlq-replay', ['id' => (string) $entry->id, '--json' => true])
        ->expectsOutputToContain('"state":"replayed"')
        ->assertExitCode(0);

    expect(DlqEntry::query()->find($entry->id)?->state)->toBe(DlqState::Replayed)
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('возвращает 1, когда запись не найдена или уже разобрана', function (): void {
    $this->artisan('notifications:dlq-replay', ['id' => '999'])->assertExitCode(1);

    $resolved = dlqConsoleEntry('2026-11-06 10:00:00+00:00', [
        'state' => DlqState::Resolved->value,
        'resolved_at' => '2026-11-06 12:00:00+00:00',
    ]);

    $this->artisan('notifications:dlq-replay', ['id' => (string) $resolved->id])->assertExitCode(1);
});

it('возвращает 2 на некорректный идентификатор записи', function (): void {
    $this->artisan('notifications:dlq-replay', ['id' => 'abc'])->assertExitCode(2);
    $this->artisan('notifications:dlq-replay', ['id' => '0'])->assertExitCode(2);
});

it('удаляет записи старше срока и не трогает журнал уведомлений', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $old = dlqConsoleEntry('2026-06-01 10:00:00+00:00');
    $recent = dlqConsoleEntry('2026-11-06 10:00:00+00:00');
    $notifications = DB::table('notifications')->count();

    $this->artisan('notifications:dlq-purge', ['--days' => '30', '--json' => true])
        ->expectsOutputToContain('"deleted":1')
        ->assertExitCode(0);

    expect(DlqEntry::query()->find($old->id))->toBeNull()
        ->and(DlqEntry::query()->find($recent->id))->not->toBeNull()
        ->and(DB::table('notifications')->count())->toBe($notifications);
});

it('возвращает 2 на некорректный срок хранения', function (): void {
    $this->artisan('notifications:dlq-purge', ['--days' => '0'])->assertExitCode(2);
});

it('регистрирует очистку DLQ в расписании одним событием', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $matches = array_values(array_filter(
        $events,
        static fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), 'notifications:dlq-purge'),
    ));

    expect($matches)->toHaveCount(1);

    /** @var array<string, mixed> $event */
    $event = $matches[0];
    $cron = (string) config('notifications.retention.purge_cron');

    expect($cron)->not->toBeEmpty()

        ->and((string) $event['expression'])->toContain($cron);
});

it('печатает таблицу и сообщение о пустой очереди без JSON', function (): void {
    expect(Artisan::call('notifications:dlq-list'))->toBe(0);
    expect(trim(Artisan::output()))->toContain('Записей очереди необработанных нет.');

    dlqConsoleEntry('2026-11-06 10:00:00+00:00', ['channel' => 'push']);

    expect(Artisan::call('notifications:dlq-list'))->toBe(0);
    $output = (string) Artisan::output();

    expect($output)->toContain('Записей: 1.')
        ->and($output)->not->toContain('@');
});

it('печатает ошибку ключа канала без JSON', function (): void {
    expect(Artisan::call('notifications:dlq-list', ['--channel' => 'sms']))->toBe(2);
    expect(trim(Artisan::output()))->toContain('Неизвестный канал');
});

it('ограничивает порцию максимумом конфигурации', function (): void {
    config(['notifications.dlq.max_page_limit' => 2]);

    dlqConsoleEntry('2026-11-06 10:00:00+00:00');
    dlqConsoleEntry('2026-11-06 11:00:00+00:00');
    dlqConsoleEntry('2026-11-06 12:00:00+00:00');

    expect(Artisan::call('notifications:dlq-list', ['--limit' => '100']))->toBe(0);

    expect(trim(Artisan::output()))->toContain('Записей: 2.');
});

it('печатает повтор и его ошибку без JSON', function (): void {
    $entry = dlqConsoleEntry('2026-11-06 10:00:00+00:00');

    expect(Artisan::call('notifications:dlq-replay', ['id' => (string) $entry->id]))->toBe(0);
    expect(trim(Artisan::output()))->toContain('поставлена на повтор');

    expect(Artisan::call('notifications:dlq-replay', ['id' => '999']))->toBe(1);
    expect(trim(Artisan::output()))->not->toBeEmpty();
});

it('печатает очистку и её ошибку без JSON', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    dlqConsoleEntry('2026-06-01 10:00:00+00:00');

    expect(Artisan::call('notifications:dlq-purge', ['--days' => '30']))->toBe(0);
    expect(trim(Artisan::output()))->toContain('Удалено записей старше 30 дн.: 1.');

    expect(Artisan::call('notifications:dlq-purge', ['--days' => '0']))->toBe(2);
    expect(trim(Artisan::output()))->toContain('положительным');
});
