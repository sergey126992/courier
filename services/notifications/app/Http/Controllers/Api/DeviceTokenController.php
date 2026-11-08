<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Problem\ApiProblem;
use App\Http\Problem\ErrorCode;
use App\Http\Resources\DeviceTokenResource;
use App\Services\Notifications\DeviceTokens\DeviceTokenService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Служебный API device-токенов (ЧТЗ 8.1, FR-NTF-525…529).
 *
 * Маршруты закрыты сервисным токеном: единственный вызывающий — монолит и
 * тесты, мобильного клиента в M13 нет (ЧТЗ 3.3). Токен нигде не возвращается
 * (FR-NTF-529). Регистрация идемпотентна по токену: `201` — новая строка,
 * `200` — обновлённая (FR-NTF-525). Отзыв идемпотентен: `204` — отозван,
 * `202` — уже был отозван, `404` — неизвестный идентификатор (FR-NTF-526).
 * Ошибки полей — `422` с кодом `DEVICE_TOKEN_INVALID` в `application/problem+json`.
 */
final class DeviceTokenController extends Controller
{
    public function __construct(private readonly DeviceTokenService $devices) {}

    /**
     * Регистрация или повторная регистрация токена устройства.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'min:20', 'max:512', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'platform' => ['required', 'string', Rule::in(['android', 'ios', 'web'])],
            'locale' => ['nullable', 'string', 'max:8'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            throw new ApiProblem(ErrorCode::DeviceTokenInvalid);
        }

        $data = $validator->validated();

        [$token, $created] = $this->devices->register([
            'user_id' => (int) $data['user_id'],
            'token' => (string) $data['token'],
            'platform' => (string) $data['platform'],
            'locale' => isset($data['locale']) && is_string($data['locale']) ? $data['locale'] : null,
            'app_version' => isset($data['app_version']) && is_string($data['app_version']) ? $data['app_version'] : null,
        ]);

        return ApiResponse::json(['data' => DeviceTokenResource::toArray($token)], $created ? 201 : 200);
    }

    /**
     * Отзыв токена устройства.
     */
    public function revoke(int $id): Response
    {
        [, $alreadyRevoked] = $this->devices->revoke($id);

        return response()->noContent($alreadyRevoked ? 202 : 204);
    }
}
