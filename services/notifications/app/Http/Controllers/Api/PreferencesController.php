<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\PreferenceRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\PreferenceResource;
use App\Services\Notifications\Preferences\PreferenceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Предпочтения получателя (ЧТЗ 8.1, 8.3, 8.4; FR-NTF-451…462).
 *
 * Маршруты закрыты сервисным токеном: единственный вызывающий — монолит,
 * пользовательских сессий у сервиса нет, а `user_id` пришёл из доверенного
 * вызова (FR-NTF-461). `GET` отдаёт эффективные значения: отсутствие
 * сохранённых строк ошибкой не считается и даёт дефолт матрицы (FR-NTF-452).
 * `PATCH` проверяет весь набор до записи и пишет строки в одной транзакции:
 * при ошибке не сохраняется ни одна строка (ЧТЗ 8.4). Ошибки полей —
 * `422` с кодом причины в формате `application/problem+json` (ЧТЗ 8.3).
 */
final class PreferencesController extends Controller
{
    public function __construct(private readonly PreferenceService $preferences) {}

    /**
     * Эффективные предпочтения получателя.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['nullable', 'string', Rule::in($this->roleValues())],
        ]);

        return ApiResponse::json(
            PreferenceResource::toArray($this->preferences->effective(
                (int) $validated['user_id'],
                $this->role($validated['role'] ?? null),
            )),
        );
    }

    /**
     * Сохранение набора строк предпочтений и сброс отдельных типов событий.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['nullable', 'string', Rule::in($this->roleValues())],
            'preferences' => ['required', 'array'],
        ]);

        /** @var list<mixed> $rows */
        $rows = array_values((array) $validated['preferences']);

        return ApiResponse::json(
            PreferenceResource::toArray($this->preferences->save(
                (int) $validated['user_id'],
                $rows,
                $this->role($validated['role'] ?? null),
            )),
        );
    }

    /**
     * Роль из запроса: без роли выдача — объединение наборов типов событий.
     */
    private function role(mixed $value): ?PreferenceRole
    {
        return is_string($value) && $value !== '' ? PreferenceRole::from($value) : null;
    }

    /**
     * Допустимые значения параметра `role`.
     *
     * @return list<string>
     */
    private function roleValues(): array
    {
        return array_map(static fn (PreferenceRole $role): string => $role->value, PreferenceRole::cases());
    }
}
