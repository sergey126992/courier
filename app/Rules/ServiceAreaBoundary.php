<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Geography\Exceptions\InvalidCoordinates;
use App\Domain\Geography\Exceptions\InvalidServiceArea;
use App\Domain\Geography\ServiceArea;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Граница зоны: одна ошибка на любой дефект структуры и смысла.
 *
 * Структура (тип, набор точек, диапазоны координат) проверяется здесь заранее,
 * поэтому клиент не получает лавину вложенных сообщений по каждому полю.
 * Если структура верна, смысл границы проверяет доменный ServiceArea
 * (порядок углов, число точек, вырожденность), и его сообщение уходит клиенту.
 *
 * Координаты точки — latitude и longitude: та же форма, что у адреса в API.
 */
final class ServiceAreaBoundary implements ValidationRule
{
    /**
     * Единственное сообщение о некорректном формате границы.
     */
    public const FORMAT_MESSAGE = 'Граница зоны: укажите тип `rectangle` с углами `south_west`/`north_east` '
        .'или `polygon` с минимум тремя точками; координаты точек — `latitude`/`longitude`.';

    /**
     * Минимальное число точек многоугольника.
     */
    private const MIN_POLYGON_POINTS = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->hasValidStructure($value)) {
            $fail(self::FORMAT_MESSAGE);

            return;
        }

        try {
            /** @var array<string, mixed> $value */
            ServiceArea::fromArray($value);
        } catch (InvalidServiceArea|InvalidCoordinates $exception) {
            $fail('Граница зоны: '.$exception->getMessage());
        }
    }

    /**
     * Проверка формы: тип границы, обязательные точки и диапазоны координат.
     */
    private function hasValidStructure(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $type = $value['type'] ?? null;

        if ($type === ServiceArea::TYPE_RECTANGLE) {
            return $this->isPoint($value['south_west'] ?? null)
                && $this->isPoint($value['north_east'] ?? null);
        }

        if ($type === ServiceArea::TYPE_POLYGON) {
            $points = $value['points'] ?? null;

            if (! is_array($points) || count($points) < self::MIN_POLYGON_POINTS) {
                return false;
            }

            foreach ($points as $point) {
                if (! $this->isPoint($point)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Точка границы: числовые latitude и longitude в допустимых диапазонах.
     */
    private function isPoint(mixed $point): bool
    {
        if (! is_array($point)
            || ! isset($point['latitude'], $point['longitude'])
            || ! is_numeric($point['latitude'])
            || ! is_numeric($point['longitude'])) {
            return false;
        }

        $latitude = (float) $point['latitude'];
        $longitude = (float) $point['longitude'];

        return $latitude >= -90.0 && $latitude <= 90.0
            && $longitude >= -180.0 && $longitude <= 180.0;
    }
}
