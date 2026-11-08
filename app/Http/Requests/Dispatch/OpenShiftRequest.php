<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Models\Dispatch\CourierShift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Открытие смены курьером (ЧТЗ 8.1).
 *
 * Клиент задаёт только зону: время начала и курьера определяет сервер,
 * поэтому эти поля отклоняются правилом prohibited.
 */
final class OpenShiftRequest extends FormRequest
{
    /**
     * Смену открывает курьер с профилем; остальные роли получают 403.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', CourierShift::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Пустая зона означает «все зоны» (FR-DSP-101, 114).
            'zone_id' => ['sometimes', 'nullable', 'integer', Rule::exists('service_zones', 'id')],

            // Поля состояния смены заполняет сервер.
            'courier_id' => ['prohibited'],
            'started_at' => ['prohibited'],
            'ended_at' => ['prohibited'],
        ];
    }
}
