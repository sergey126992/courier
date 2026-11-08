<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Pricing\Distance;
use App\Models\Geography\AddressDirectoryEntry;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Провайдер-заглушка для проверки кэша расстояний.
 *
 * Считает вызовы и умеет падать заданным исключением: так проверяются
 * и защита от повторного расчёта, и отсутствие кэша у ошибки.
 */
final class FakeDistanceProvider implements DistanceProvider
{
    public int $calls = 0;

    public function __construct(
        public readonly float $kilometers = 12.5,
        private readonly ?Throwable $failure = null,
    ) {}

    public function between(Coordinates $from, Coordinates $to): Distance
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return Distance::fromKilometers($this->kilometers);
    }
}

/**
 * Прямоугольная граница в формате API и хранения.
 *
 * @return array<string, mixed>
 */
function rectangleBoundary(
    float $southWestLatitude = 55.60,
    float $southWestLongitude = 37.40,
    float $northEastLatitude = 55.90,
    float $northEastLongitude = 37.80,
): array {
    return [
        'type' => 'rectangle',
        'south_west' => ['latitude' => $southWestLatitude, 'longitude' => $southWestLongitude],
        'north_east' => ['latitude' => $northEastLatitude, 'longitude' => $northEastLongitude],
    ];
}

/**
 * Активная зона, покрывающая маршруты тестов расчёта вокруг центра Москвы.
 */
function estimateServiceZone(): ServiceZone
{
    return ServiceZone::factory()->create([
        'code' => 'msk-tests',
        'name' => 'Тестовая зона Москвы',
        'boundary' => rectangleBoundary(),
    ]);
}

/**
 * Активная запись справочника адресов внутри тестовой зоны.
 *
 * Значения по умолчанию — центр Москвы: маршрут по таким адресам попадает
 * в зону `estimateServiceZone()`, поэтому расчёт проходит без отказа.
 *
 * @param array<string, mixed> $overrides
 */
function addressInZone(array $overrides = []): AddressDirectoryEntry
{
    $address = array_merge([
        'district' => 'Тверской',
        'street' => 'Тверская',
        'house' => '7',
        'latitude' => 55.755826,
        'longitude' => 37.6173,
    ], $overrides);

    return AddressDirectoryEntry::factory()
        ->forAddress(
            (string) $address['district'],
            (string) $address['street'],
            (string) $address['house'],
            (float) $address['latitude'],
            (float) $address['longitude'],
        )
        ->create();
}

/**
 * Запись справочника вне тестовой зоны: проверка отказа по адресу.
 *
 * Координаты Санкт-Петербурга лежат за прямоугольником тестовой зоны Москвы.
 */
function addressOutsideZone(): AddressDirectoryEntry
{
    return AddressDirectoryEntry::factory()
        ->forAddress('Центральный', 'Невский проспект', '28', 59.938784, 30.314997)
        ->create();
}

/**
 * Тело запроса создания зоны.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function zonePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'msk-center',
        'name' => 'Центр Москвы',
        'boundary' => rectangleBoundary(),
        'base_fee_amount' => 19900,
        'currency' => 'RUB',
    ], $overrides);
}

/**
 * Администратор с действующим токеном.
 */
function geographyAsAdmin(): User
{
    $user = User::factory()->admin()->create();
    Sanctum::actingAs($user);

    return $user;
}
