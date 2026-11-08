<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Профиль пользователя: данные аккаунта плюс профиль его роли.
 *
 * Пароль и служебные поля не отдаются: состав полей аккаунта задаёт UserResource.
 *
 * @mixin User
 */
final class ProfileResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $profile = match (true) {
            $user->senderProfile !== null => SenderProfileResource::make($user->senderProfile),
            $user->courierProfile !== null => CourierProfileResource::make($user->courierProfile),
            default => null,
        };

        return [
            ...UserResource::make($user)->resolve($request),
            'profile' => $profile,
        ];
    }
}
