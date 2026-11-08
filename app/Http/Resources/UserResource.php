<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Публичные данные пользователя: без пароля и служебных полей.
 *
 * @mixin User
 */
final class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $verifiedAt = $user->email_verified_at;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'email_verified_at' => $verifiedAt === null ? null : Carbon::parse($verifiedAt)->toIso8601String(),
        ];
    }
}
