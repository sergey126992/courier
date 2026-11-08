<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Детерминированные адреса: на них опираются демонстрация и тесты.
        User::factory()->sender()->create([
            'name' => 'Отправитель Демо',
            'email' => 'sender@courier.test',
        ]);

        User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create([
            'name' => 'Курьер Демо',
            'email' => 'courier@courier.test',
        ]);

        User::factory()->dispatcher()->create([
            'name' => 'Диспетчер Демо',
            'email' => 'dispatcher@courier.test',
        ]);

        User::factory()->admin()->create([
            'name' => 'Администратор Демо',
            'email' => 'admin@courier.test',
        ]);

        // Демо-география: зона Москвы и справочник адресов для формы заказа.
        $this->call(GeographyDemoSeeder::class);

        // Демонстрационный набор M10: учётные записи, зоны, заказы во всех статусах.
        $this->call(DemoSeeder::class);
    }
}
