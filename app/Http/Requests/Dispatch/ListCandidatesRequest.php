<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Models\Order\DeliveryOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Параметры списка кандидатов на заказ (ЧТЗ 8.2, п. 6).
 *
 * Правило подбора необязательно: без него берётся значение конфигурации.
 * Неизвестное имя отклоняется валидацией с 422 VALIDATION_FAILED по полю
 * strategy (FR-DSP-125), а не превращается в доменную ошибку.
 */
final class ListCandidatesRequest extends FormRequest
{
    /**
     * Список кандидатов видит только диспетчер: отправитель, курьер и
     * администратор получают 403 (раздел 6 ЧТЗ).
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof DeliveryOrder
            && ($this->user()?->can('viewCandidates', $order) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'strategy' => ['sometimes', 'string', Rule::enum(AssignmentStrategy::class)],
        ];
    }

    /**
     * Имя правила из запроса; null — применяется значение конфигурации.
     */
    public function strategy(): ?string
    {
        $strategy = $this->validated('strategy');

        return is_string($strategy) ? $strategy : null;
    }
}
