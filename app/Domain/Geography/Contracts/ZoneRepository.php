<?php

declare(strict_types=1);

namespace App\Domain\Geography\Contracts;

use App\Domain\Geography\Zone;

/**
 * Порт чтения справочника зон обслуживания.
 *
 * Домен получает готовые доменные зоны и не знает ни о базе, ни о Eloquent.
 */
interface ZoneRepository
{
    /**
     * Активные зоны обслуживания.
     *
     * @return list<Zone>
     */
    public function active(): array;
}
