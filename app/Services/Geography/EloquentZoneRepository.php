<?php

declare(strict_types=1);

namespace App\Services\Geography;

use App\Domain\Geography\Contracts\ZoneRepository;
use App\Domain\Geography\ServiceArea;
use App\Domain\Geography\Zone;
use App\Domain\Pricing\Money;
use App\Models\Geography\ServiceZone;

/**
 * Порт зон на Eloquent: модели превращаются в доменные зоны.
 *
 * Домен получает только активные зоны: деактивированная зона не участвует
 * в проверке новых адресов, но остаётся в истории заказов.
 */
final readonly class EloquentZoneRepository implements ZoneRepository
{
    /**
     * @return list<Zone>
     */
    public function active(): array
    {
        /** @var list<Zone> $zones */
        $zones = ServiceZone::query()
            ->active()
            ->orderBy('code')
            ->get()
            ->map(static fn (ServiceZone $zone): Zone => self::toDomain($zone))
            ->values()
            ->all();

        return $zones;
    }

    private static function toDomain(ServiceZone $zone): Zone
    {
        return new Zone(
            code: (string) $zone->code,
            name: (string) $zone->name,
            serviceArea: ServiceArea::fromArray((array) $zone->boundary),
            baseFee: Money::fromMinor((int) $zone->base_fee_amount, (string) $zone->currency),
            isActive: (bool) $zone->is_active,
        );
    }
}
