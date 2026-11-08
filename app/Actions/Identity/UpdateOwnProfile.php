<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\SenderProfile;
use App\Models\User;
use App\Services\Events\UserContactChangedOutbox;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Изменение собственных данных пользователя.
 *
 * Роль и служебные поля операция не принимает: их отсекают запрос и модель.
 * Профиль отправителя создаётся при первом изменении, если его ещё нет
 * (например, аккаунт заведён сидом вручную).
 *
 * Смена почты пишет событие контактов в той же транзакции: сервис оповещений
 * получает новый адрес из потока контактов, а не из чужой базы (FR-NTF-231).
 * Само событие в журнал не попадает — email это персональные данные (FR-NTF-235).
 */
final class UpdateOwnProfile
{
    public function __construct(private readonly UserContactChangedOutbox $contacts) {}

    /**
     * @param array<string, mixed> $data проверенные данные запроса
     */
    public function handle(User $user, array $data): User
    {
        DB::transaction(function () use ($user, $data): void {
            $name = Arr::get($data, 'name');

            if (is_string($name)) {
                $user->fill(['name' => $name])->save();
            }

            // Поля профиля отправителя правит сам отправитель; у курьера тип
            // транспорта и активность администрируются, а не меняются пользователем.
            if ($user->role->hasSenderProfile()) {
                /** @var SenderProfile $profile */
                $profile = $user->senderProfile()->firstOrCreate([]);
                $profile->fill(Arr::only($data, ['phone', 'company', 'default_address']))->save();
            }

            // Смена почты — событие контактов в той же транзакции (FR-NTF-231).
            $email = Arr::get($data, 'email');

            if (is_string($email) && $this->emailChanged($user, $email)) {
                $user->fill(['email' => mb_strtolower(trim($email))])->save();

                $this->contacts->record($user);
            }
        });

        return $user->refresh();
    }

    /**
     * Почта изменилась по существу: регистр и пробелы не считаются сменой.
     */
    private function emailChanged(User $user, string $email): bool
    {
        $normalized = mb_strtolower(trim($email));

        return $normalized !== '' && $normalized !== mb_strtolower((string) $user->email);
    }
}
