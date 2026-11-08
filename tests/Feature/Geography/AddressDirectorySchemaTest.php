<?php

declare(strict_types=1);

use App\Models\Geography\AddressDirectoryEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('создаёт таблицу справочника адресов с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('address_directory_entries', [
        'id',
        'district',
        'street',
        'house',
        'latitude',
        'longitude',
        'is_active',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('хранит адрес с ожидаемыми типами и длинами', function (): void {
    $columns = collect(DB::select(
        <<<'SQL'
        select column_name, data_type, character_maximum_length, numeric_precision, numeric_scale
        from information_schema.columns
        where table_name = 'address_directory_entries'
        SQL
    ))->keyBy('column_name');

    expect($columns['district']->data_type)->toBe('character varying')
        ->and($columns['district']->character_maximum_length)->toBe(255)
        ->and($columns['street']->data_type)->toBe('character varying')
        ->and($columns['street']->character_maximum_length)->toBe(255)
        ->and($columns['house']->data_type)->toBe('character varying')
        ->and($columns['house']->character_maximum_length)->toBe(64)
        // Координаты — numeric(9,6): шесть знаков после запятой.
        ->and($columns['latitude']->data_type)->toBe('numeric')
        ->and($columns['latitude']->numeric_precision)->toBe(9)
        ->and($columns['latitude']->numeric_scale)->toBe(6)
        ->and($columns['longitude']->data_type)->toBe('numeric')
        ->and($columns['longitude']->numeric_precision)->toBe(9)
        ->and($columns['longitude']->numeric_scale)->toBe(6)
        ->and($columns['is_active']->data_type)->toBe('boolean');
});

it('сохраняет дом через фабрику', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '1', 55.757600, 37.605400)
        ->create();

    $this->assertDatabaseHas('address_directory_entries', [
        'id' => $entry->id,
        'district' => 'Тверской',
        'street' => 'Тверская улица',
        'house' => '1',
        'is_active' => true,
    ]);
});

it('приводит координаты к строкам с шестью знаками, а активность к булеву', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '1', 55.757600, 37.605400)
        ->create();

    $entry->refresh();

    expect($entry->latitude)->toBe('55.757600')
        ->and($entry->longitude)->toBe('37.605400')
        ->and($entry->is_active)->toBeTrue();
});

it('активен по умолчанию, если признак не передан', function (): void {
    $entry = AddressDirectoryEntry::create([
        'district' => 'Арбат',
        'street' => 'Улица Арбат',
        'house' => '10',
        'latitude' => 55.751500,
        'longitude' => 37.593800,
    ])->fresh();

    expect($entry?->is_active)->toBeTrue();
});

it('не допускает второй дом с тем же адресом в другом регистре', function (): void {
    AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '1')
        ->create();

    expect(static fn () => AddressDirectoryEntry::factory()
        ->forAddress('тверской', 'ТВЕРСКАЯ УЛИЦА', '1')
        ->create())
        ->toThrow(QueryException::class);
});

it('разрешает два разных дома на одной улице', function (): void {
    AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '1')
        ->create();
    AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '7')
        ->create();

    expect(AddressDirectoryEntry::query()->where('street', 'Тверская улица')->count())->toBe(2);
});

it('отдаёт скоупом только активные записи', function (): void {
    AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '1')
        ->create();
    AddressDirectoryEntry::factory()
        ->inactive()
        ->forAddress('Тверской', 'Тверская улица', '7')
        ->create();

    $houses = AddressDirectoryEntry::query()->active()->pluck('house')->all();

    expect($houses)->toBe(['1']);
});
