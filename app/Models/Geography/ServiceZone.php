<?php

declare(strict_types=1);

namespace App\Models\Geography;

use Database\Factories\Geography\ServiceZoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Зона обслуживания: граница и базовая ставка.
 *
 * Код неизменяем и уникален без учёта регистра; граница хранится структурой
 * (прямоугольник или многоугольник), ставка — в минорных единицах.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<string, mixed> $boundary
 * @property int $base_fee_amount
 * @property string $currency
 * @property bool $is_active
 */
class ServiceZone extends Model
{
    /** @use HasFactory<ServiceZoneFactory> */
    use HasFactory;

    /**
     * Поля, доступные массовому присвоению.
     *
     * Служебные поля (id, временные метки) назначаются базой и сюда не входят.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'boundary',
        'base_fee_amount',
        'currency',
        'is_active',
    ];

    /**
     * Значения по умолчанию на уровне модели.
     *
     * Дублируют DEFAULT базы: новый экземпляр совпадает с сохранённой записью,
     * поэтому ответ на создание не отдаёт null вместо валюты и признака активности.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'RUB',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'base_fee_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Только активные зоны: они участвуют в проверке адресов.
     *
     * @param Builder<ServiceZone> $query
     * @return Builder<ServiceZone>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
