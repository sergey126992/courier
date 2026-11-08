<?php

declare(strict_types=1);

namespace App\Domain\Geography;

use App\Domain\Geography\Exceptions\InvalidCoordinates;
use App\Domain\Geography\Exceptions\InvalidServiceArea;

/**
 * Граница зоны обслуживания: прямоугольник или многоугольник.
 *
 * Внутри границы хранятся опорные точки: две для прямоугольника
 * (юго-западный и северо-восточный углы) и не меньше трёх для многоугольника.
 * Точка на ребре считается попавшей в зону. Площадь нужна для правила
 * пересечения зон и считается в квадратных километрах по плоской проекции
 * на среднюю широту — этого достаточно для города и его окрестностей.
 */
final readonly class ServiceArea
{
    /**
     * Тип границы «прямоугольник».
     */
    public const TYPE_RECTANGLE = 'rectangle';

    /**
     * Тип границы «многоугольник».
     */
    public const TYPE_POLYGON = 'polygon';

    /**
     * Километров в одном градусе широты (среднее по меридиану).
     */
    private const KM_PER_LATITUDE_DEGREE = 111.32;

    /**
     * Минимальное число точек многоугольника.
     */
    private const MIN_POLYGON_POINTS = 3;

    /**
     * Допуск сравнения координат в градусах: точка на ребре считается внутри.
     */
    private const EPSILON = 1e-9;

    /**
     * Порог вырожденности границы в квадратных километрах.
     */
    private const MIN_AREA_KM2 = 1e-12;

    /**
     * @param list<Coordinates> $points
     */
    private function __construct(
        private array $points,
        private string $type,
    ) {}

    /**
     * Прямоугольник по юго-западному и северо-восточному углам.
     *
     * @throws InvalidServiceArea углы заданы в обратном порядке или граница вырождена
     */
    public static function fromRectangle(Coordinates $southWest, Coordinates $northEast): self
    {
        if ($northEast->latitude() - $southWest->latitude() < self::EPSILON
            || $northEast->longitude() - $southWest->longitude() < self::EPSILON) {
            throw InvalidServiceArea::forRectangleOrder();
        }

        return new self([$southWest, $northEast], self::TYPE_RECTANGLE);
    }

    /**
     * Многоугольник по списку точек в порядке обхода.
     *
     * @param list<Coordinates> $points
     *
     * @throws InvalidServiceArea точек меньше трёх или граница вырождена
     */
    public static function fromPoints(array $points): self
    {
        if (count($points) < self::MIN_POLYGON_POINTS) {
            throw InvalidServiceArea::forTooFewPoints(count($points));
        }

        $area = new self($points, self::TYPE_POLYGON);

        if ($area->projectedArea() <= self::MIN_AREA_KM2) {
            throw InvalidServiceArea::forDegenerate();
        }

        return $area;
    }

    /**
     * Граница из структуры хранения.
     *
     * Прямоугольник: `['type' => 'rectangle', 'south_west' => ['latitude' =>, 'longitude' =>], 'north_east' => [...]]`.
     * Многоугольник: `['type' => 'polygon', 'points' => [['latitude' =>, 'longitude' =>], ...]]`.
     * Форма координат та же, что у адреса в API: latitude и longitude.
     *
     * @param array<string, mixed> $boundary
     *
     * @throws InvalidServiceArea структура не описывает границу
     * @throws InvalidCoordinates точка границы вне допустимого диапазона
     */
    public static function fromArray(array $boundary): self
    {
        $type = $boundary['type'] ?? null;

        if ($type === self::TYPE_RECTANGLE) {
            return self::fromRectangle(
                self::pointFromArray($boundary['south_west'] ?? null),
                self::pointFromArray($boundary['north_east'] ?? null),
            );
        }

        if ($type === self::TYPE_POLYGON) {
            $rawPoints = $boundary['points'] ?? null;

            if (! is_array($rawPoints)) {
                throw InvalidServiceArea::forBoundary($type);
            }

            return self::fromPoints(array_map(
                static fn (mixed $point): Coordinates => self::pointFromArray($point),
                array_values($rawPoints),
            ));
        }

        throw InvalidServiceArea::forBoundary($type);
    }

    /**
     * Тип границы: прямоугольник или многоугольник.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Опорные точки границы в порядке обхода.
     *
     * @return list<Coordinates>
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * Попадает ли точка в границу; точка на ребре считается попавшей.
     */
    public function contains(Coordinates $point): bool
    {
        return $this->type === self::TYPE_RECTANGLE
            ? $this->rectangleContains($point)
            : $this->polygonContains($point);
    }

    /**
     * Площадь границы в квадратных километрах.
     */
    public function area(): float
    {
        return round($this->projectedArea(), 6);
    }

    /**
     * Структура хранения границы.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type === self::TYPE_RECTANGLE) {
            return [
                'type' => self::TYPE_RECTANGLE,
                'south_west' => $this->points[0]->toArray(),
                'north_east' => $this->points[1]->toArray(),
            ];
        }

        return [
            'type' => self::TYPE_POLYGON,
            'points' => array_map(
                static fn (Coordinates $point): array => $point->toArray(),
                $this->points,
            ),
        ];
    }

    private function rectangleContains(Coordinates $point): bool
    {
        [$southWest, $northEast] = $this->points;

        return $point->latitude() >= $southWest->latitude() - self::EPSILON
            && $point->latitude() <= $northEast->latitude() + self::EPSILON
            && $point->longitude() >= $southWest->longitude() - self::EPSILON
            && $point->longitude() <= $northEast->longitude() + self::EPSILON;
    }

    /**
     * Проверка «точка внутри многоугольника»: сначала ребро, затем трассировка луча.
     */
    private function polygonContains(Coordinates $point): bool
    {
        $x = $point->longitude();
        $y = $point->latitude();
        $count = count($this->points);
        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $current = $this->points[$i];
            $previous = $this->points[$j];
            $xi = $current->longitude();
            $yi = $current->latitude();
            $xj = $previous->longitude();
            $yj = $previous->latitude();

            if (self::isOnSegment($x, $y, $xj, $yj, $xi, $yi)) {
                return true;
            }

            if ((($yi > $y) !== ($yj > $y))
                && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Лежит ли точка на отрезке с допуском в градусах.
     */
    private static function isOnSegment(
        float $x,
        float $y,
        float $startX,
        float $startY,
        float $endX,
        float $endY,
    ): bool {
        $lengthSquared = ($endX - $startX) ** 2 + ($endY - $startY) ** 2;

        if ($lengthSquared < self::EPSILON ** 2) {
            return abs($x - $startX) < self::EPSILON && abs($y - $startY) < self::EPSILON;
        }

        $cross = ($endX - $startX) * ($y - $startY) - ($endY - $startY) * ($x - $startX);

        if (abs($cross) / sqrt($lengthSquared) > self::EPSILON) {
            return false;
        }

        $projection = ($x - $startX) * ($endX - $startX) + ($y - $startY) * ($endY - $startY);

        return $projection >= -self::EPSILON && $projection <= $lengthSquared + self::EPSILON;
    }

    /**
     * Площадь границы в квадратных километрах без округления.
     */
    private function projectedArea(): float
    {
        if ($this->type === self::TYPE_RECTANGLE) {
            [$southWest, $northEast] = $this->points;
            $height = ($northEast->latitude() - $southWest->latitude()) * self::KM_PER_LATITUDE_DEGREE;
            $width = ($northEast->longitude() - $southWest->longitude())
                * self::KM_PER_LATITUDE_DEGREE
                * cos(deg2rad(($southWest->latitude() + $northEast->latitude()) / 2));

            return abs($height * $width);
        }

        $count = count($this->points);
        $referenceLatitude = $this->meanLatitude();
        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $current = $this->points[$i];
            $next = $this->points[($i + 1) % $count];
            $currentX = $this->projectLongitude($current, $referenceLatitude);
            $currentY = $current->latitude() * self::KM_PER_LATITUDE_DEGREE;
            $nextX = $this->projectLongitude($next, $referenceLatitude);
            $nextY = $next->latitude() * self::KM_PER_LATITUDE_DEGREE;
            $sum += $currentX * $nextY - $nextX * $currentY;
        }

        return abs($sum) / 2;
    }

    private function meanLatitude(): float
    {
        $sum = 0.0;

        foreach ($this->points as $point) {
            $sum += $point->latitude();
        }

        return $sum / count($this->points);
    }

    private function projectLongitude(Coordinates $point, float $referenceLatitude): float
    {
        return $point->longitude() * self::KM_PER_LATITUDE_DEGREE * cos(deg2rad($referenceLatitude));
    }

    /**
     * @throws InvalidServiceArea структура точки некорректна
     * @throws InvalidCoordinates координата вне диапазона или точнее шести знаков
     */
    private static function pointFromArray(mixed $point): Coordinates
    {
        if (! is_array($point) || ! isset($point['latitude'], $point['longitude'])
            || ! is_numeric($point['latitude']) || ! is_numeric($point['longitude'])) {
            throw InvalidServiceArea::forPoint();
        }

        return new Coordinates((float) $point['latitude'], (float) $point['longitude']);
    }
}
