<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Geography\AddressDirectoryEntry;
use App\Models\Geography\ServiceZone;
use Illuminate\Database\Seeder;

/**
 * Демонстрационные данные географии: зона Москвы и справочник адресов.
 *
 * Сидер идемпотентен: зона обновляется по коду, адреса ищутся по паре
 * «район + улица + дом», поэтому повторный запуск не падает на уникальном
 * индексе и не создаёт дубликатов. Заказы и пользователи здесь не создаются.
 */
class GeographyDemoSeeder extends Seeder
{
    /**
     * Код демо-зоны: на него опираются демонстрация и тесты.
     */
    private const ZONE_CODE = 'msk-demo';

    public function run(): void
    {
        $this->seedZone();

        foreach ($this->addresses() as $address) {
            AddressDirectoryEntry::query()->firstOrCreate(
                [
                    'district' => $address['district'],
                    'street' => $address['street'],
                    'house' => $address['house'],
                ],
                [
                    'latitude' => $address['latitude'],
                    'longitude' => $address['longitude'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Активная демо-зона Москвы: прямоугольник вокруг центра.
     */
    private function seedZone(): void
    {
        ServiceZone::query()->updateOrCreate(
            ['code' => self::ZONE_CODE],
            [
                'name' => 'Москва (демо)',
                'boundary' => [
                    'type' => 'rectangle',
                    'south_west' => ['latitude' => 55.60, 'longitude' => 37.40],
                    'north_east' => ['latitude' => 55.90, 'longitude' => 37.80],
                ],
                'base_fee_amount' => 19900,
                'currency' => 'RUB',
                'is_active' => true,
            ],
        );
    }

    /**
     * Адреса демо-справочника: двадцать внутри зоны и два вне её.
     *
     * Адреса вне зоны нужны, чтобы сценарий «адрес вне зоны обслуживания»
     * оставался воспроизводимым через выбор адреса из справочника.
     *
     * @return list<array{district: string, street: string, house: string, latitude: float, longitude: float}>
     */
    private function addresses(): array
    {
        return [
            // Внутри демо-зоны Москвы.
            ['district' => 'Тверской', 'street' => 'Тверская улица', 'house' => '1', 'latitude' => 55.757600, 'longitude' => 37.605400],
            ['district' => 'Тверской', 'street' => 'Тверская улица', 'house' => '7', 'latitude' => 55.760100, 'longitude' => 37.608200],
            ['district' => 'Тверской', 'street' => 'Малая Дмитровка', 'house' => '4', 'latitude' => 55.765200, 'longitude' => 37.606800],
            ['district' => 'Арбат', 'street' => 'Улица Арбат', 'house' => '10', 'latitude' => 55.751500, 'longitude' => 37.593800],
            ['district' => 'Арбат', 'street' => 'Новый Арбат', 'house' => '15', 'latitude' => 55.752700, 'longitude' => 37.583900],
            ['district' => 'Арбат', 'street' => 'Смоленская улица', 'house' => '6', 'latitude' => 55.747500, 'longitude' => 37.581200],
            ['district' => 'Пресненский', 'street' => 'Красная Пресня', 'house' => '12', 'latitude' => 55.762300, 'longitude' => 37.564800],
            ['district' => 'Пресненский', 'street' => 'Баррикадная улица', 'house' => '2', 'latitude' => 55.761900, 'longitude' => 37.581700],
            ['district' => 'Пресненский', 'street' => 'Улица Климашкина', 'house' => '9', 'latitude' => 55.767700, 'longitude' => 37.571100],
            ['district' => 'Басманный', 'street' => 'Покровка', 'house' => '5', 'latitude' => 55.759500, 'longitude' => 37.641500],
            ['district' => 'Басманный', 'street' => 'Покровка', 'house' => '17', 'latitude' => 55.763800, 'longitude' => 37.644900],
            ['district' => 'Басманный', 'street' => 'Мясницкая улица', 'house' => '20', 'latitude' => 55.763600, 'longitude' => 37.634300],
            ['district' => 'Хамовники', 'street' => 'Остоженка', 'house' => '8', 'latitude' => 55.740900, 'longitude' => 37.596800],
            ['district' => 'Хамовники', 'street' => 'Пречистенка', 'house' => '14', 'latitude' => 55.743600, 'longitude' => 37.593200],
            ['district' => 'Хамовники', 'street' => 'Комсомольский проспект', 'house' => '26', 'latitude' => 55.727700, 'longitude' => 37.586400],
            ['district' => 'Замоскворечье', 'street' => 'Пятницкая улица', 'house' => '9', 'latitude' => 55.741600, 'longitude' => 37.626900],
            ['district' => 'Замоскворечье', 'street' => 'Большая Ордынка', 'house' => '15', 'latitude' => 55.739400, 'longitude' => 37.624100],
            ['district' => 'Мещанский', 'street' => 'Проспект Мира', 'house' => '18', 'latitude' => 55.775800, 'longitude' => 37.631700],
            ['district' => 'Мещанский', 'street' => 'Улица Щепкина', 'house' => '33', 'latitude' => 55.779400, 'longitude' => 37.628600],
            ['district' => 'Таганский', 'street' => 'Таганская улица', 'house' => '21', 'latitude' => 55.740500, 'longitude' => 37.655300],

            // Вне демо-зоны: сценарий отказа ADDRESS_OUTSIDE_SERVICE_AREA.
            ['district' => 'Санкт-Петербург, Центральный район', 'street' => 'Невский проспект', 'house' => '1', 'latitude' => 59.931100, 'longitude' => 30.360900],
            ['district' => 'Санкт-Петербург, Адмиралтейский район', 'street' => 'Садовая улица', 'house' => '2', 'latitude' => 59.929400, 'longitude' => 30.312100],
        ];
    }
}
