<?php

declare(strict_types=1);

use App\Models\Events\ProcessedMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Идентификатор события для guard-тестов Inbox.
 */
function asyncEventId(): string
{
    return '3f6c9f5e-1d76-4f0c-9b7a-2a1f2f4d1c11';
}

it('создаёт таблицу отметок Inbox без времени изменения', function (): void {
    expect(Schema::hasColumns('processed_messages', ['id', 'message_id', 'handler', 'processed_at', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('processed_messages', 'updated_at'))->toBeFalse();
});

it('заполняет отметку обработчиком и временем обработки', function (): void {
    $mark = ProcessedMessage::factory()->create([
        'message_id' => asyncEventId(),
        'handler' => 'notifications.on_order_created',
    ]);

    $row = (array) DB::table('processed_messages')->where('id', $mark->getKey())->first();

    expect($mark->processed_at)->not->toBeNull()
        ->and($row['handler'])->toBe('notifications.on_order_created')
        ->and($row['processed_at'])->toBeString()
        ->and($row['created_at'])->not->toBeNull();
});

it('не допускает вторую отметку того же обработчика', function (): void {
    ProcessedMessage::factory()->create([
        'message_id' => asyncEventId(),
        'handler' => 'notifications.on_order_created',
    ]);

    expect(static fn () => ProcessedMessage::factory()->create([
        'message_id' => asyncEventId(),
        'handler' => 'notifications.on_order_created',
    ]))->toThrow(QueryException::class);
});

it('разрешает вторую отметку другому обработчику', function (): void {
    ProcessedMessage::factory()->create([
        'message_id' => asyncEventId(),
        'handler' => 'notifications.on_order_created',
    ]);
    ProcessedMessage::factory()->create([
        'message_id' => asyncEventId(),
        'handler' => 'tracking.on_order_created',
    ]);

    expect(ProcessedMessage::query()->count())->toBe(2);
});
