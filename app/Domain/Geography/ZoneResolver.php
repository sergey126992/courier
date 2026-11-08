<?php

declare(strict_types=1);

namespace App\Domain\Geography;

use App\Domain\Geography\Contracts\ZoneRepository;
use App\Domain\Geography\Exceptions\AddressOutsideServiceArea;

/**
 * Определяет зону обслуживания по координатам адреса.
 *
 * Доменный сервис: правило выбора — часть предметной области, а хранилище зон
 * скрыто за портом ZoneRepository. При пересечении границ выигрывает зона
 * с меньшей площадью, при равенстве площадей — зона с меньшим кодом, поэтому
 * результат не зависит от порядка зон в выборке.
 */
final readonly class ZoneResolver
{
    public function __construct(
        private ZoneRepository $zones,
    ) {}

    /**
     * @throws AddressOutsideServiceArea адрес не попал ни в одну активную зону
     */
    public function resolve(Coordinates $coordinates): Zone
    {
        $candidates = array_values(array_filter(
            $this->zones->active(),
            static fn (Zone $zone): bool => $zone->isActive() && $zone->contains($coordinates),
        ));

        if ($candidates === []) {
            throw AddressOutsideServiceArea::forCoordinates($coordinates);
        }

        usort($candidates, static function (Zone $left, Zone $right): int {
            return ($left->area() <=> $right->area()) ?: strcmp($left->code(), $right->code());
        });

        return $candidates[0];
    }
}
