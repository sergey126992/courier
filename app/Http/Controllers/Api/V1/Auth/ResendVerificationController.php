<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\SendEmailVerification;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Повторная отправка письма подтверждения авторизованному пользователю.
 */
final class ResendVerificationController extends Controller
{
    public function __invoke(Request $request, SendEmailVerification $sendEmailVerification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sendEmailVerification->handle($user);

        return ApiResponse::json(
            ['message' => __('auth.verification_sent')],
            Response::HTTP_ACCEPTED,
        );
    }
}
