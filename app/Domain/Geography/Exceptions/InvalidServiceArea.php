<?php

declare(strict_types=1);

namespace App\Domain\Geography\Exceptions;

use DomainException;

/**
 * Некорректная граница зоны обслуживания.
 */
final class InvalidServiceArea extends DomainException
{
    public static function forRectangleOrder(): self
    {
        return new self('У прямоугольника юго-западный угол обязан быть меньше северо-восточного по обеим координатам.');
    }

    public static function forTooFewPoints(int $count): self
    {
        return new self(sprintf('Многоугольник зоны обязан содержать не меньше трёх точек, получено %d.', $count));
    }

    public static function forDegenerate(): self
    {
        return new self('Граница зоны вырождена: её площадь равна нулю.');
    }

    public static function forBoundary(mixed $type): self
    {
        return new self(sprintf(
            'Граница зоны обязана быть прямоугольником или многоугольником, получено «%s».',
            is_scalar($type) ? (string) $type : get_debug_type($type),
        ));
    }

    public static function forPoint(): self
    {
        return new self('Точка границы зоны обязана содержать координаты широты и долготы.');
    }
}
