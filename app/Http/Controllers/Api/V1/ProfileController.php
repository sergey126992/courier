<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Identity\UpdateOwnProfile;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Профиль пользователя: просмотр своих данных и изменение разрешённых полей.
 *
 * Права целиком определяет политика UserPolicy. Ответ на чужой идентификатор —
 * 404, чтобы не раскрывать существование записи (FR-API-106).
 */
final class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        $user = $this->currentUser($request);
        $user->loadMissing(['senderProfile', 'courierProfile']);

        return ProfileResource::make($user);
    }

    public function update(UpdateProfileRequest $request, UpdateOwnProfile $updateOwnProfile): ProfileResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $user = $updateOwnProfile->handle($this->currentUser($request), $data);
        $user->loadMissing(['senderProfile', 'courierProfile']);

        return ProfileResource::make($user);
    }

    /**
     * Просмотр пользователя по идентификатору: чужая запись неотличима от отсутствующей.
     */
    public function showUser(Request $request, User $user): ProfileResource
    {
        if (! $this->currentUser($request)->can('viewProfile', $user)) {
            throw ApiException::notFound();
        }

        $user->loadMissing(['senderProfile', 'courierProfile']);

        return ProfileResource::make($user);
    }

    /**
     * Текущий пользователь: маршруты закрыты auth:sanctum, поэтому он всегда есть.
     */
    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
