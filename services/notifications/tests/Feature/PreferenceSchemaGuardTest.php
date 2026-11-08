<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Guard-тесты схемы предпочтений (ЧТЗ 7.1, ЧТЗ 9.2 п. 2)
|--------------------------------------------------------------------------
|
| Уникальность пары «пользователь — тип события» и структурные CHECK
| держит база, а не проверка перед вставкой (ЧТЗ 12.1). Состав каналов,
| локаль, окно и критичность проверяет приложение, поэтому в схеме нет
| литеральных перечней.
|
*/

uses(RefreshDatabase::class);

it('создаёт таблицу предпочтений с колонками ЧТЗ 7.1', function (): void {
    expect(Schema::hasTable('notification_preferences'))->toBeTrue();

    foreach ([
        'id',
        'user_id',
        'event_type',
        'channels',
        'quiet_hours',
        'locale',
        'critical_override',
        'created_at',
        'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('notification_preferences', $column))->toBeTrue("Нет колонки {$column}");
    }
});

it('хранит jsonb и моменты времени в ожидаемых типах', function (): void {
    $types = DB::table('information_schema.columns')
        ->select('column_name', 'data_type')
        ->where('table_schema', 'public')
        ->where('table_name', 'notification_preferences')
        ->pluck('data_type', 'column_name');

    expect($types['channels'])->toBe('jsonb')
        ->and($types['quiet_hours'])->toBe('jsonb')
        ->and($types['created_at'])->toBe('timestamp with time zone')
        ->and($types['updated_at'])->toBe('timestamp with time zone');
});

it('запрещает второй строке описывать ту же пару «пользователь — тип события»', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => 500,
        'event_type' => 'order.created',
    ]);

    expectDatabaseRejection(function (): void {
        NotificationPreference::factory()->create([
            'user_id' => 500,
            'event_type' => 'order.created',
        ]);
    });

    // Другой тип события того же пользователя допустим.
    $other = NotificationPreference::factory()->create([
        'user_id' => 500,
        'event_type' => 'order.assigned',
    ]);

    expect($other->id)->toBeInt();
});

it('держит CHECK формы channels и quiet_hours', function (): void {
    expectDatabaseRejection(function (): void {
        DB::table('notification_preferences')->insert([
            'user_id' => 501,
            'event_type' => 'order.created',
            'channels' => json_encode(['email' => true], JSON_THROW_ON_ERROR),
            'quiet_hours' => null,
            'locale' => 'ru',
            'critical_override' => false,
        ]);
    });

    expectDatabaseRejection(function (): void {
        DB::table('notification_preferences')->insert([
            'user_id' => 502,
            'event_type' => 'order.created',
            'channels' => json_encode(['email'], JSON_THROW_ON_ERROR),
            'quiet_hours' => json_encode('22:00', JSON_THROW_ON_ERROR),
            'locale' => 'ru',
            'critical_override' => false,
        ]);
    });

    // Пустой массив каналов и NULL окна допустимы: «не уведомлять» (FR-NTF-456).
    DB::table('notification_preferences')->insert([
        'user_id' => 503,
        'event_type' => 'order.created',
        'channels' => json_encode([], JSON_THROW_ON_ERROR),
        'quiet_hours' => json_encode(['from' => '22:00', 'to' => '08:00', 'timezone' => 'UTC'], JSON_THROW_ON_ERROR),
        'locale' => 'ru',
        'critical_override' => false,
    ]);

    expect(DB::table('notification_preferences')->where('user_id', 503)->count())->toBe(1);
});

it('держит уникальный ключ и индекс по пользователю', function (): void {
    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'notification_preferences')
        ->pluck('indexdef', 'indexname');

    $definitions = implode("\n", array_map(static fn (mixed $value): string => (string) $value, $indexes->all()));

    expect($definitions)->toContain('UNIQUE')
        ->and($definitions)->toContain('(user_id, event_type)')
        ->and($definitions)->toContain('(user_id)');
});
