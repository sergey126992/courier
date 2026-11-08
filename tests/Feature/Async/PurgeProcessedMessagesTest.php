<?php

declare(strict_types=1);

use App\Models\Events\ProcessedMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('удаляет только отметки старше срока хранения', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    ProcessedMessage::factory()->create(['created_at' => now()->subDays(31)]);
    $fresh = ProcessedMessage::factory()->create(['created_at' => now()->subDay()]);

    $this->artisan('processed-messages:purge')->assertExitCode(0);

    expect(ProcessedMessage::query()->pluck('id')->all())->toBe([$fresh->id]);
});

it('берёт срок хранения из конфигурации', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config(['events.inbox_retention_days' => 7]);

    ProcessedMessage::factory()->create(['created_at' => now()->subDays(8)]);
    $fresh = ProcessedMessage::factory()->create(['created_at' => now()->subDays(6)]);

    $this->artisan('processed-messages:purge')->assertExitCode(0);

    expect(ProcessedMessage::query()->pluck('id')->all())->toBe([$fresh->id]);
});

it('учитывает флаг --days', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    ProcessedMessage::factory()->create(['created_at' => now()->subDays(5)]);
    $fresh = ProcessedMessage::factory()->create(['created_at' => now()->subDays(2)]);

    $this->artisan('processed-messages:purge', ['--days' => 3])->assertExitCode(0);

    expect(ProcessedMessage::query()->pluck('id')->all())->toBe([$fresh->id]);
});

it('выводит счётчики в JSON', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    ProcessedMessage::factory()->create(['created_at' => now()->subDays(31)]);

    $exit = Artisan::call('processed-messages:purge', ['--json' => true]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload)->toMatchArray([
            'deleted' => 1,
            'days' => 30,
        ]);
});

it('возвращает 2 при некорректном --days', function (): void {
    ProcessedMessage::factory()->create(['created_at' => now()->subDays(31)]);

    $this->artisan('processed-messages:purge', ['--days' => 'abc'])->assertExitCode(2);

    expect(ProcessedMessage::query()->count())->toBe(1);
});
