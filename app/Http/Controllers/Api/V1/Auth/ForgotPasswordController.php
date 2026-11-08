<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\SendPasswordResetLink;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запрос сброса пароля: ответ всегда 202 и не раскрывает существование аккаунта.
 */
final class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request, SendPasswordResetLink $sendPasswordResetLink): JsonResponse
    {
        $sendPasswordResetLink->handle($request->string('email')->toString());

        return ApiResponse::json(
            ['message' => __('auth.password_reset_sent')],
            Response::HTTP_ACCEPTED,
        );
    }
}
