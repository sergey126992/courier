<?php

declare(strict_types=1);

use App\Actions\Geography\SearchAddressDirectory;
use App\Models\Geography\AddressDirectoryEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Подсказки адреса по строке запроса.
 *
 * @return Collection<int, array{id: int, label: string, district: string, street: string, house: string}>
 */
function searchAddressDirectory(string $query): Collection
{
    return app(SearchAddressDirectory::class)->handle($query);
}

it('находит адрес по улице независимо от регистра', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Арбат', 'Тверская улица', '7')
        ->create();

    expect(searchAddressDirectory('ТВЕРСКАЯ')->pluck('id')->all())->toBe([$entry->id]);
});

it('находит адрес по части улицы', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Арбат', 'Тверская улица', '7')
        ->create();

    expect(searchAddressDirectory('твер')->pluck('id')->all())->toBe([$entry->id]);
});

it('находит адрес по району', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Улица Ленина', '7')
        ->create();

    expect(searchAddressDirectory('тверской')->pluck('id')->all())->toBe([$entry->id]);
});

it('находит адрес по номеру дома', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Арбат', 'Улица Ленина', '77Б')
        ->create();
    AddressDirectoryEntry::factory()
        ->forAddress('Арбат', 'Улица Ленина', '77А')
        ->create();

    expect(searchAddressDirectory('77б')->pluck('id')->all())->toBe([$entry->id]);
});

it('не показывает деактивированные адреса', function (): void {
    AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '1')
        ->create();
    AddressDirectoryEntry::factory()
        ->inactive()
        ->forAddress('Тверской', 'Тверская улица', '7')
        ->create();

    expect(searchAddressDirectory('тверская')->pluck('house')->all())->toBe(['1']);
});

it('не обращается к базе на пустом и односимвольном запросе', function (): void {
    AddressDirectoryEntry::factory()
        ->forAddress('Арбат', 'Тверская улица', '7')
        ->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(searchAddressDirectory(''))->toBeEmpty()
        ->and(searchAddressDirectory('   '))->toBeEmpty()
        ->and(searchAddressDirectory('т'))->toBeEmpty()
        // Короткий запрос не должен доходить до базы.
        ->and($queries)->toBe(0);
});

it('ограничивает выдачу восемью подсказками', function (): void {
    foreach (range(1, 10) as $house) {
        AddressDirectoryEntry::factory()
            ->forAddress("Район {$house}", 'Тестовая улица', (string) $house)
            ->create();
    }

    expect(searchAddressDirectory('тестовая'))->toHaveCount(8);
});

it('не подставляет шаблон LIKE из знака процента', function (): void {
    AddressDirectoryEntry::factory()
        ->forAddress('Арбат', 'Тверская улица', '7')
        ->create();
    AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Улица Ленина', '1')
        ->create();

    // Если бы «%» попадал в LIKE как шаблон, вернулись бы все записи.
    expect(searchAddressDirectory('%%'))->toBeEmpty();
});

it('отдаёт подсказки в стабильном порядке', function (): void {
    AddressDirectoryEntry::factory()->forAddress('Район А', 'Тверская улица', '10')->create();
    AddressDirectoryEntry::factory()->forAddress('Район Б', 'Тверская улица', '2')->create();
    AddressDirectoryEntry::factory()->forAddress('Район В', 'Арбат улица', '5')->create();

    $first = searchAddressDirectory('улица')->pluck('street')->all();
    $second = searchAddressDirectory('улица')->pluck('street')->all();
    $houses = searchAddressDirectory('улица')->pluck('house')->all();

    expect($first)->toBe($second)
        // lower(street), затем lower(house): «Арбат улица» раньше «Тверской»,
        // внутри улицы «10» раньше «2» по строковому сравнению.
        ->and($first)->toBe(['Арбат улица', 'Тверская улица', 'Тверская улица'])
        ->and($houses)->toBe(['5', '10', '2']);
});

it('собирает подпись из улицы, дома и района', function (): void {
    $entry = AddressDirectoryEntry::factory()
        ->forAddress('Тверской', 'Тверская улица', '7')
        ->create();

    expect(searchAddressDirectory('тверская')->first())->toMatchArray([
        'id' => $entry->id,
        'label' => 'Тверская улица, 7 — Тверской',
        'district' => 'Тверской',
        'street' => 'Тверская улица',
        'house' => '7',
    ]);
});
