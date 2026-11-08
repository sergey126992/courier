<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Печатает демонстрационные учётные записи и ключевые идентификаторы.
 *
 * Нужен ведущему показа: доступы, заказы по статусам и ссылка публичного
 * трекинга. В production команда отказывает и пароль не раскрывает.
 */
final class DemoAccounts extends Command
{
    protected $signature = 'demo:accounts';

    protected $description = 'Показывает демонстрационные учётные записи и ключевые данные для показа.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Демонстрационные учётные записи доступны только вне production.');

            return self::FAILURE;
        }

        $this->accounts();
        $this->orders();
        $this->tracking();

        return self::SUCCESS;
    }

    /**
     * Таблица учётных записей: время запуска и пароль набора.
     */
    private function accounts(): void
    {
        $this->components->info('Демонстрационные учётные записи');

        $rows = User::query()
            ->where('email', 'like', '%@courier.test')
            ->orderBy('id')
            ->get()
            ->map(function (User $user): array {
                $profile = $user->courierProfile;

                return [
                    'email' => (string) $user->email,
                    'role' => $user->role->value,
                    'transport' => $profile === null
                        ? '—'
                        : trim($profile->transport_type->value.' '.($profile->light_vehicle_kind === null
                            ? ''
                            : $profile->light_vehicle_kind->value)),
                ];
            })
            ->all();

        if ($rows === []) {
            $this->components->warn('Демо-учётных записей нет: выполните php artisan db:seed.');

            return;
        }

        $this->table(['Почта', 'Роль', 'Транспорт'], $rows);
        $this->line('Пароль всех учётных записей: '.(string) config('demo.password', 'password'));
    }

    /**
     * По одному заказу на каждый статус: номер и код трекинга.
     */
    private function orders(): void
    {
        $this->newLine();
        $this->components->info('Заказы по статусам');

        $rows = [];

        foreach (DeliveryOrder::query()->orderBy('id')->get() as $order) {
            if (array_key_exists($order->status->value, $rows)) {
                continue;
            }

            $rows[$order->status->value] = [
                'status' => $order->status->value,
                'number' => (string) $order->number,
                'tracking_code' => (string) $order->tracking_code,
                'active_assignment' => Assignment::query()
                    ->where('order_id', $order->getKey())
                    ->active()
                    ->exists() ? 'да' : 'нет',
            ];
        }

        if ($rows === []) {
            $this->components->warn('Заказов нет: выполните php artisan db:seed.');

            return;
        }

        $this->table(['Статус', 'Номер', 'Код трекинга', 'Активное назначение'], array_values($rows));
    }

    /**
     * Код трекинга и эндпоинт выдачи подписанной ссылки.
     */
    private function tracking(): void
    {
        $order = DeliveryOrder::query()->orderBy('id')->first();

        if ($order === null) {
            return;
        }

        $this->newLine();
        $this->components->info('Публичный трекинг');
        $this->line('Код трекинга первого заказа: '.$order->tracking_code);
        $this->line('Подписанную ссылку выдаёт GET /api/v1/orders/'.$order->getKey().'/tracking-link '
            .'под токеном отправителя: страница трекинга открывается без авторизации.');
    }
}
