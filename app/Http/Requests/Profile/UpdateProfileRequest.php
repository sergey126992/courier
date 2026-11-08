<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Изменение собственного профиля.
 *
 * Права проверяет политика UserPolicy, здесь остаётся только состав полей.
 * Поле роли запрещено явно: роль назначается администратором (FR-IDN-310).
 */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('updateProfile', $user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            // Смена роли через API профиля запрещена.
            'role' => ['prohibited'],
            // Транспорт и активность курьера назначает администратор, а не сам курьер.
            'transport_type' => ['prohibited'],
            'light_vehicle_kind' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];

        // Курьеру доступно только имя: транспорт и активность администрируются,
        // поэтому поля профиля отправителя для него закрыты.
        if (! $this->editsSenderProfile()) {
            return $rules + [
                'phone' => ['prohibited'],
                'company' => ['prohibited'],
                'default_address' => ['prohibited'],
            ];
        }

        return $rules + [
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_address' => ['sometimes', 'nullable', 'array'],
            'default_address.district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_address.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_address.house' => ['sometimes', 'nullable', 'string', 'max:32'],
            'default_address.latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'default_address.longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Правит ли текущий пользователь поля профиля отправителя.
     */
    private function editsSenderProfile(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->role->hasSenderProfile();
    }
}
