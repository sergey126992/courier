<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Identity\UpdateOwnProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Профиль пользователя: просмотр своих данных и правка разрешённых полей.
 *
 * Права и состав полей целиком определяет политика UserPolicy через
 * UpdateProfileRequest, поэтому проверок роли в контроллере нет (FR-UI-118).
 */
final class AccountController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['senderProfile', 'courierProfile']);

        return view('account', ['user' => $user]);
    }

    public function update(UpdateProfileRequest $request, UpdateOwnProfile $updateOwnProfile): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $updateOwnProfile->handle($user, $this->withSavedCoordinates($user, $data));

        return redirect()
            ->route('account')
            ->with('status', __('ui.profile.saved'));
    }

    /**
     * Форма правит только видимые поля адреса: координаты остаются прежними,
     * а полностью очищенный адрес сохраняется как отсутствующий.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withSavedCoordinates(User $user, array $data): array
    {
        if (! array_key_exists('default_address', $data)) {
            return $data;
        }

        $input = is_array($data['default_address']) ? $data['default_address'] : [];
        $hasText = filled($input['district'] ?? null)
            || filled($input['street'] ?? null)
            || filled($input['house'] ?? null);

        if (! $hasText) {
            $data['default_address'] = null;

            return $data;
        }

        $data['default_address'] = array_merge($user->senderProfile->default_address ?? [], $input);

        return $data;
    }
}
