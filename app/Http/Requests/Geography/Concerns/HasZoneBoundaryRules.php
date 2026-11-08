<?php

declare(strict_types=1);

namespace App\Http\Requests\Geography\Concerns;

use App\Rules\ServiceAreaBoundary;

/**
 * Правила границы зоны, общие для создания и изменения.
 *
 * Граница проверяется одним правилом: структура и смысл дают ровно одно
 * сообщение по полю boundary, без лавины вложенных ошибок по точкам.
 */
trait HasZoneBoundaryRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function boundaryRules(bool $isRequired): array
    {
        return [
            'boundary' => [$isRequired ? 'required' : 'sometimes', new ServiceAreaBoundary],
        ];
    }
}
