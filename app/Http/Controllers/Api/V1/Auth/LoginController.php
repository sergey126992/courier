<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\LoginUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;

/**
 * Вход: проверка учётных данных и лимит попыток — в операции, ответ — ресурс с токеном.
 */
final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, LoginUser $loginUser): UserResource
    {
        ['user' => $user, 'token' => $token] = $loginUser->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            (string) $request->ip(),
        );

        return UserResource::make($user)->additional(['token' => $token]);
    }
}
