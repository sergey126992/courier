<?php

declare(strict_types=1);

use App\Models\Order\OutboxMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Строка исходящего события: поля публикации заполняют база и модель.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function asyncOutboxRow(array $overrides = []): array
{
    return array_merge([
        'aggregate_type' => 'delivery_order',
        'aggregate_id' => 1,
        'event_type' => 'order.created',
        'payload' => ['order_id' => 1],
    ], $overrides);
}

it('добавляет к outbox поля публикации', function (): void {
    expect(Schema::hasColumns('outbox_messages', ['message_id', 'last_error', 'available_at']))->toBeTrue();
});

it('заполняет идентификатор сообщения и время готовности', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $message = OutboxMessage::create(asyncOutboxRow());

    $row = (array) DB::table('outbox_messages')->where('id', $message->getKey())->first();
    $availableAt = Carbon::parse((string) $row['available_at']);

    expect($row['message_id'])->toBeString()
        ->and($row['message_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        // Момент хранится в UTC: приложение записало «сейчас» без смещения.
        ->and($availableAt->equalTo(now()->utc()))->toBeTrue()
        ->and($message->available_at)->not->toBeNull();
});

it('не допускает второй идентификатор сообщения', function (): void {
    $message = OutboxMessage::create(asyncOutboxRow());
    $message->refresh();

    expect(static fn () => DB::table('outbox_messages')->insert([
        'message_id' => $message->message_id,
        'aggregate_type' => 'delivery_order',
        'aggregate_id' => 2,
        'event_type' => 'order.created',
        'payload' => json_encode(['order_id' => 2], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'available_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('не берёт опубликованные строки в выборку неопубликованных', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $pending = OutboxMessage::create(asyncOutboxRow());
    $published = OutboxMessage::create(asyncOutboxRow());
    $published->update(['published_at' => now()]);

    // Условия публикатора: только неопубликованные строки, готовые к отправке.
    $ids = OutboxMessage::query()
        ->whereNull('published_at')
        ->where('available_at', '<=', now())
        ->orderBy('available_at')
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect(array_map(static fn ($id): int => (int) $id, $ids))->toBe([$pending->getKey()]);
});
