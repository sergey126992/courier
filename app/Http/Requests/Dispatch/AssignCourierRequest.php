<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Models\Dispatch\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Данные назначения и переназначения курьера (ЧТЗ 8.2).
 *
 * Оба поля необязательны: без courier_id выполняется автоматический подбор,
 * без strategy берётся правило из config/dispatch.php. Неизвестное правило
 * отклоняется валидацией с 422 VALIDATION_FAILED, а не доменной ошибкой.
 */
final class AssignCourierRequest extends FormRequest
{
    /**
     * Назначение выполняет только диспетчер: остальные роли получают 403.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('assign', Assignment::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'courier_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'strategy' => ['sometimes', 'nullable', 'string', Rule::enum(AssignmentStrategy::class)],
        ];
    }

    /**
     * Конкретный курьер; null — автоматический подбор.
     */
    public function courierId(): ?int
    {
        $courierId = $this->validated('courier_id');

        return is_numeric($courierId) ? (int) $courierId : null;
    }

    /**
     * Правило из запроса; null — применяется значение конфигурации.
     */
    public function strategy(): ?AssignmentStrategy
    {
        $strategy = $this->validated('strategy');

        return is_string($strategy) ? AssignmentStrategy::tryFrom($strategy) : null;
    }
}
