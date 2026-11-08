<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationStatus;
use App\Models\ChannelBinding;
use App\Models\InboxMessage;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\NotificationDedup;
use App\Models\OutboxMessage;
use App\Models\PushMessage;
use App\Models\RecipientContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Guard-тесты схемы сервиса (ЧТЗ 7.1, ЧТЗ 9.2 п. 2)
|--------------------------------------------------------------------------
|
| Схема держит ограничения сама: дедупликация и «ровно один эффект»
| подтверждаются базой, а не проверкой перед вставкой (ЧТЗ 7, п. 3).
| Моменты времени объявлены как timestamptz (ЧТЗ 7, п. 5), внешних ключей
| на таблицы монолита нет (ЧТЗ 7, п. 2).
|
*/

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function serviceTables(): array
{
    return [
        'notifications',
        'notification_attempts',
        'notification_dedup',
        'inbox_messages',
        'outbox_messages',
        'recipient_contacts',
        'channel_bindings',
        'push_messages',
        'telegram_link_tokens',
        'notification_preferences',
        'dlq_entries',
    ];
}

it('создаёт все таблицы сервиса', function (): void {
    foreach (serviceTables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Таблица {$table} не создана");
    }
});

it('хранит моменты времени как timestamptz и не заводит времени изменения там, где строки не меняются', function (): void {
    /** @var array<string, list<string>> $expected */
    $expected = [
        'notifications' => ['sent_at', 'next_attempt_at', 'deferred_until', 'created_at', 'updated_at'],
        'notification_attempts' => ['created_at'],
        'notification_dedup' => ['created_at'],
        'inbox_messages' => ['received_at', 'processed_at', 'created_at'],
        'outbox_messages' => ['published_at', 'available_at', 'created_at'],
        'recipient_contacts' => ['created_at', 'updated_at'],
        'channel_bindings' => ['verified_at', 'revoked_at', 'created_at', 'updated_at'],
        'push_messages' => ['sent_at', 'created_at'],
        'notification_preferences' => ['created_at', 'updated_at'],
        'dlq_entries' => ['failed_at', 'last_replayed_at', 'resolved_at', 'created_at', 'updated_at'],
    ];

    /** @var array<string, array<string, string>> $actual */
    $actual = [];

    $rows = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type')
        ->where('table_schema', 'public')
        ->whereIn('table_name', serviceTables())
        ->get();

    foreach ($rows as $row) {
        $actual[(string) $row->table_name][(string) $row->column_name] = (string) $row->data_type;
    }

    foreach ($expected as $table => $columns) {
        foreach ($columns as $column) {
            expect($actual[$table][$column] ?? null)
                ->toBe('timestamp with time zone', "Колонка {$table}.{$column} должна быть timestamptz");
        }
    }

    expect(Schema::hasColumn('notification_attempts', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('push_messages', 'updated_at'))->toBeFalse();
});

it('запрещает дубль уведомления по событию, получателю и каналу', function (): void {
    $notification = Notification::factory()->create();

    expectDatabaseRejection(function () use ($notification): void {
        Notification::factory()->create([
            'event_id' => $notification->event_id,
            'recipient_id' => $notification->recipient_id,
            'channel' => $notification->channel,
        ]);
    });
});

it('запрещает недопустимый статус уведомления', function (): void {
    expectDatabaseRejection(function (): void {
        DB::table('notifications')->insert([
            'event_id' => '7f2f0f9e-0000-4000-8000-000000000001',
            'recipient_id' => 10,
            'recipient_role' => 'customer',
            'channel' => 'email',
            'event_type' => 'order.created',
            'template' => 'order.created',
            'status' => 'bogus',
            'attempts' => 0,
        ]);
    });
});

it('держит частичный индекс плановой задержки отправки', function (): void {
    $index = (string) DB::table('pg_indexes')
        ->where('tablename', 'notifications')
        ->where('indexname', 'notifications_next_attempt_at_index')
        ->value('indexdef');

    expect($index)->toContain('(next_attempt_at)')
        ->and($index)->toContain('next_attempt_at IS NOT NULL');
});

it('держит частичный индекс отложенной отправки', function (): void {
    $index = (string) DB::table('pg_indexes')
        ->where('tablename', 'notifications')
        ->where('indexname', 'notifications_deferred_until_index')
        ->value('indexdef');

    expect($index)->toContain('(deferred_until)')
        ->and($index)->toContain('deferred_until IS NOT NULL');
});

it('принимает все статусы уведомления из CHECK', function (): void {
    foreach (NotificationStatus::cases() as $status) {
        $notification = Notification::factory()->create(['status' => $status->value]);

        expect($notification->refresh()->status)->toBe($status);
    }
});

it('запрещает дубль попытки и удаляет попытки вместе с уведомлением', function (): void {
    $notification = Notification::factory()->create();

    NotificationAttempt::factory()->create([
        'notification_id' => $notification->id,
        'attempt' => 1,
    ]);

    expectDatabaseRejection(function () use ($notification): void {
        NotificationAttempt::factory()->create([
            'notification_id' => $notification->id,
            'attempt' => 1,
        ]);
    });

    $notification->delete();

    expect(NotificationAttempt::query()->where('notification_id', $notification->id)->count())->toBe(0);
});

it('запрещает дубль ключа дедупликации', function (): void {
    $key = NotificationDedup::factory()->create();

    expectDatabaseRejection(function () use ($key): void {
        NotificationDedup::factory()->create([
            'event_id' => $key->event_id,
            'recipient_id' => $key->recipient_id,
            'channel' => $key->channel,
        ]);
    });
});

it('запрещает дубль сообщения Inbox по паре message_id и consumer', function (): void {
    $message = InboxMessage::factory()->create();

    expectDatabaseRejection(function () use ($message): void {
        InboxMessage::factory()->create([
            'message_id' => $message->message_id,
            'consumer' => $message->consumer,
        ]);
    });

    // Другой потребитель то же сообщение обработать может.
    $other = InboxMessage::factory()->create([
        'message_id' => $message->message_id,
        'consumer' => 'notifications-service-2',
    ]);

    expect($other->id)->toBeInt();
});

it('выдаёт message_id Outbox по умолчанию и запрещает дубль', function (): void {
    $message = OutboxMessage::factory()->create();
    $message->refresh();

    expect($message->message_id)->toBeString()->not->toBeEmpty();

    expectDatabaseRejection(function () use ($message): void {
        DB::table('outbox_messages')->insert([
            'message_id' => $message->message_id,
            'event_type' => 'notification.sent',
            'schema_version' => 'v1',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'payload' => json_encode(['notification_id' => 1], JSON_THROW_ON_ERROR),
            'available_at' => now(),
        ]);
    });
});

it('держит частичный индекс неопубликованных сообщений Outbox', function (): void {
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'outbox_messages')
        ->where('indexname', 'outbox_messages_pending_index')
        ->value('indexdef');

    expect((string) $definition)
        ->toContain('available_at, created_at, id')
        ->toContain('WHERE (published_at IS NULL)');
});

it('держит одну активную привязку канала и освобождает ключ после отзыва', function (): void {
    $binding = ChannelBinding::factory()->create(['user_id' => 4242]);

    expectDatabaseRejection(function (): void {
        ChannelBinding::factory()->create(['user_id' => 4242, 'channel' => 'email']);
    });

    $binding->update(['revoked_at' => now()]);

    $replacement = ChannelBinding::factory()->create(['user_id' => 4242, 'channel' => 'email']);

    expect($replacement->id)->toBeInt();
});

it('запрещает неизвестный канал привязки', function (): void {
    expectDatabaseRejection(function (): void {
        DB::table('channel_bindings')->insert([
            'user_id' => 777,
            'channel' => 'sms',
            'address' => 'value',
        ]);
    });
});

it('обнуляет ссылку push-сообщения при удалении уведомления', function (): void {
    $notification = Notification::factory()->create();

    $message = PushMessage::factory()->create([
        'notification_id' => $notification->id,
        'recipient_id' => 31337,
    ]);

    $notification->delete();

    $fresh = $message->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh?->notification_id)->toBeNull()
        ->and($fresh?->recipient_id)->toBe(31337);
});

it('держит первичный ключ контактов по идентификатору пользователя', function (): void {
    RecipientContact::factory()->create(['user_id' => 5001]);

    expectDatabaseRejection(function (): void {
        RecipientContact::factory()->create(['user_id' => 5001]);
    });
});

it('не имеет внешних ключей на таблицы монолита', function (): void {
    $foreignKeys = DB::table('pg_constraint as c')
        ->join('pg_class as src', 'src.oid', '=', 'c.conrelid')
        ->join('pg_class as dst', 'dst.oid', '=', 'c.confrelid')
        ->where('c.contype', 'f')
        ->select('src.relname as source_table', 'dst.relname as target_table')
        ->get();

    expect($foreignKeys)->not->toBeEmpty();

    foreach ($foreignKeys as $foreignKey) {
        expect(in_array((string) $foreignKey->target_table, serviceTables(), true))
            ->toBeTrue("Внешний ключ {$foreignKey->source_table} → {$foreignKey->target_table} ведёт за пределы схемы сервиса");
    }
});
