<?php

declare(strict_types=1);

namespace App\Models\Geography;

use Database\Factories\Geography\AddressDirectoryEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Запись справочника адресов.
 *
 * Одна запись — один конкретный дом: район, улица, номер дома и координаты
 * точки. Адрес уникален без учёта регистра, поэтому одинаковый дом нельзя
 * завести дважды. Деактивированная запись остаётся в справочнике, но
 * в подсказках не участвует.
 *
 * @property int $id
 * @property string $district
 * @property string $street
 * @property string $house
 * @property string $latitude
 * @property string $longitude
 * @property bool $is_active
 */
class AddressDirectoryEntry extends Model
{
    /** @use HasFactory<AddressDirectoryEntryFactory> */
    use HasFactory;

    /**
     * Поля, доступные массовому присвоению.
     *
     * Служебные поля (id, временные метки) назначаются базой и сюда не входят.
     *
     * @var list<string>
     */
    protected $fillable = [
        'district',
        'street',
        'house',
        'latitude',
        'longitude',
        'is_active',
    ];

    /**
     * Значения по умолчанию на уровне модели.
     *
     * Дублируют DEFAULT базы: новый экземпляр совпадает с сохранённой записью,
     * поэтому ответ на создание не отдаёт null вместо признака активности.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Координаты читаются строками с шестью знаками после запятой.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Только действующие записи: они участвуют в подсказках адреса.
     *
     * @param Builder<AddressDirectoryEntry> $query
     * @return Builder<AddressDirectoryEntry>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Подпись адреса для подсказок: «Улица, дом — район».
     *
     * Формат единый для всего приложения: собирается здесь, чтобы шаблоны
     * и ответы не дублировали правила и не расходились между собой.
     */
    public function label(): string
    {
        return sprintf('%s, %s — %s', $this->street, $this->house, $this->district);
    }
}
