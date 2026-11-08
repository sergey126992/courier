<?php

declare(strict_types=1);

namespace App\Http\Requests\Geography;

use App\Http\Requests\Geography\Concerns\HasZoneBoundaryRules;
use App\Models\Geography\ServiceZone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Изменение зоны: название, граница, ставка и признак активности.
 *
 * Код не меняется (FR-GEO-103), удаление заменяется деактивацией (FR-GEO-105).
 */
final class UpdateZoneRequest extends FormRequest
{
    use HasZoneBoundaryRules;

    /**
     * Справочник зон ведёт администратор (FR-GEO-102).
     */
    public function authorize(): bool
    {
        $zone = $this->route('zone');

        return $zone instanceof ServiceZone
            && ($this->user()?->can('update', $zone) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Код зоны неизменяем: попытка прислать поле отклоняется явно.
            'code' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->boundaryRules(isRequired: false),
            'base_fee_amount' => ['sometimes', 'required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
