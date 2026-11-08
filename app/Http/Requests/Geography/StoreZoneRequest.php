<?php

declare(strict_types=1);

namespace App\Http\Requests\Geography;

use App\Http\Requests\Geography\Concerns\HasZoneBoundaryRules;
use App\Models\Geography\ServiceZone;
use App\Rules\UniqueZoneCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Создание зоны обслуживания.
 *
 * Код задаётся один раз: он уникален без учёта регистра и дальше не меняется.
 */
final class StoreZoneRequest extends FormRequest
{
    use HasZoneBoundaryRules;

    /**
     * Справочник зон ведёт администратор (FR-GEO-102).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ServiceZone::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Код: нижний регистр, цифры и дефисы, 2–16 символов.
            'code' => [
                'required',
                'string',
                'min:2',
                'max:16',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                new UniqueZoneCode,
            ],
            'name' => ['required', 'string', 'max:255'],
            ...$this->boundaryRules(isRequired: true),
            'base_fee_amount' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Код приводится к нижнему регистру до валидации формата.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => Str::lower(trim($code))]);
        }
    }
}
