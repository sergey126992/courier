<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Уникальность email без учёта регистра.
 *
 * Сравнение через lower() совпадает с уникальным индексом в базе
 * и не зависит от регистра введённого адреса.
 */
final class UniqueEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $exists = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($value)])
            ->exists();

        if ($exists) {
            $fail(__('validation.unique', ['attribute' => __('validation.attributes.email')]));
        }
    }
}
