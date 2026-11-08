<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\RegisterSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Регистрация отправителя: валидация в запросе, работа — в операции.
 */
final class RegisterController extends Controller
{
    #[ResponseAttribute(status: Response::HTTP_CREATED, description: 'Аккаунт создан, дальнейшие запросы — с токеном.')]
    public function __invoke(RegisterRequest $request, RegisterSender $registerSender): UserResource
    {
        $user = $registerSender->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return UserResource::make($user)
            ->withStatus(Response::HTTP_CREATED)
            ->withHeaders(['Location' => url('/api/v1/profile')]);
    }
}
