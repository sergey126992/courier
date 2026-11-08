<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\ResetPassword;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Установка нового пароля по подписанной ссылке из письма.
 */
final class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request, ResetPassword $resetPassword): JsonResponse
    {
        $resetPassword->handle(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::json(['message' => __('auth.password_reset')]);
    }
}
