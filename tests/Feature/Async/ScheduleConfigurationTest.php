<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
| FR-NTF-105, FR-NTF-137: расписание содержит публикатор Outbox с интервалом из
| конфигурации и ежедневную очистку отметок Inbox. Список берётся из schedule:list
| в JSON-виде — это тот же источник, по которому расписание читает администратор.
*/

/**
 * События расписания в JSON-виде schedule:list.
 *
 * @return list<array<string, mixed>>
 */
function scheduledEvents(): array
{
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    return $events;
}

/**
 * Событие расписания по фрагменту команды или по описанию задачи.
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>|null
 */
function scheduledEvent(array $events, string $needle): ?array
{
    foreach ($events as $event) {
        $command = (string) ($event['command'] ?? '');

        if (str_contains($command, $needle) || ($event['description'] ?? null) === $needle) {
            return $event;
        }
    }

    return null;
}

it('перечисляет расписание без ошибок', function (): void {
    expect(Artisan::call('schedule:list'))->toBe(0);
});

it('публикует Outbox по расписанию с интервалом из конфигурации', function (): void {
    $publish = scheduledEvent(scheduledEvents(), 'outbox:publish');

    if ($publish === null) {
        throw new RuntimeException('Задача публикатора отсутствует в расписании.');
    }

    expect($publish['command'])->toContain('outbox:publish')
        ->and($publish['description'])->toBe('events:publish-outbox')
        ->and($publish['repeat_seconds'])->toBe((int) config('events.publish_interval_seconds'));
});

it('чистит отметки Inbox ежедневно', function (): void {
    $purge = scheduledEvent(scheduledEvents(), 'processed-messages:purge');

    if ($purge === null) {
        throw new RuntimeException('Задача очистки отметок Inbox отсутствует в расписании.');
    }

    expect((string) $purge['expression'])->toEndWith('0 0 * * *');
});
