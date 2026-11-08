<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\User;
use App\Services\Events\UserContactChangedOutbox;
use Illuminate\Support\Facades\DB;

/**
 * Регистрация отправителя: аккаунт и профиль создаются одной транзакцией.
 *
 * Роль намеренно не передаётся: модель и база выставляют «отправитель»,
 * а массовое присвоение роль не меняет.
 */
final class RegisterSender
{
    public function __construct(
        private readonly SendEmailVerification $sendEmailVerification,
        private readonly UserContactChangedOutbox $contacts,
    ) {}

    /**
     * @param string|null $verificationRouteName маршрут ссылки в письме; null — значение по умолчанию
     */
    public function handle(string $name, string $email, string $password, ?string $verificationRouteName = null): User
    {
        $user = DB::transaction(function () use ($name, $email, $password): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->senderProfile()->create([]);

            // Контакты получателя публикуются событием в той же транзакции:
            // сервис оповещений не читает базу монолита (FR-NTF-231).
            $this->contacts->record($user);

            return $user;
        });

        // Письмо ставится в очередь только после фиксации транзакции.
        $this->sendEmailVerification->handle($user, $verificationRouteName);

        return $user;
    }
}
