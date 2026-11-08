<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\VerifyEmail;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Подтверждение email по подписанной ссылке.
 */
final class VerifyEmailController extends Controller
{
    public function __invoke(int $id, string $hash, VerifyEmail $verifyEmail): JsonResponse
    {
        $verifyEmail->handle($id, $hash);

        return ApiResponse::json(['message' => __('auth.email_verified')]);
    }
}
