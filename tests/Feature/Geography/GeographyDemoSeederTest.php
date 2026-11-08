<?php

declare(strict_types=1);

use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\AddressOutsideServiceArea;
use App\Domain\Geography\ZoneResolver;
use App\Models\Geography\AddressDirectoryEntry;
use App\Models\Geography\ServiceZone;
use Database\Seeders\GeographyDemoSeeder;

it('создаёт активную демо-зону Москвы', function (): void {
    $this->seed(GeographyDemoSeeder::class);

    $zone = ServiceZone::query()->where('code', 'msk-demo')->first();

    expect($zone)->not->toBeNull()
        ->and($zone?->name)->toBe('Москва (демо)')
        ->and($zone?->is_active)->toBeTrue()
        ->and($zone?->base_fee_amount)->toBe(19900)
        ->and($zone?->currency)->toBe('RUB');
});

it('наполняет справочник адресами внутри и вне зоны', function (): void {
    $this->seed(GeographyDemoSeeder::class);

    $resolver = app(ZoneResolver::class);
    $inside = 0;
    $outside = 0;

    foreach (AddressDirectoryEntry::query()->get() as $entry) {
        try {
            $zone = $resolver->resolve(new Coordinates(
                (float) $entry->latitude,
                (float) $entry->longitude,
            ));
        } catch (AddressOutsideServiceArea) {
            $outside++;

            continue;
        }

        expect($zone->code())->toBe('msk-demo');
        $inside++;
    }

    expect($inside)->toBeGreaterThanOrEqual(20)
        ->and($outside)->toBeGreaterThanOrEqual(1)
        // Адрес вне зоны нужен для сценария ADDRESS_OUTSIDE_SERVICE_AREA.
        ->and(AddressDirectoryEntry::query()->where('street', 'Невский проспект')->exists())->toBeTrue();
});

it('отклоняет адрес из справочника, лежащий вне зоны', function (): void {
    $this->seed(GeographyDemoSeeder::class);

    $entry = AddressDirectoryEntry::query()
        ->where('street', 'Невский проспект')
        ->where('house', '1')
        ->firstOrFail();

    expect(static fn () => app(ZoneResolver::class)->resolve(new Coordinates(
        (float) $entry->latitude,
        (float) $entry->longitude,
    )))->toThrow(AddressOutsideServiceArea::class);
});

it('не создаёт дубликатов при повторном запуске', function (): void {
    $this->seed(GeographyDemoSeeder::class);

    $addresses = AddressDirectoryEntry::query()->count();
    $zones = ServiceZone::query()->count();

    $this->seed(GeographyDemoSeeder::class);

    expect(AddressDirectoryEntry::query()->count())->toBe($addresses)
        ->and(ServiceZone::query()->count())->toBe($zones);
});
