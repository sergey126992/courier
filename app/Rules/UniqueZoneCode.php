<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Geography\ServiceZone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Уникальность кода зоны без учёта регистра (FR-GEO-103).
 *
 * Индекс базы построен по lower(code), поэтому проверка идёт тем же сравнением:
 * иначе PostgreSQL отклонил бы вставку уже после валидации.
 */
final class UniqueZoneCode implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $query = ServiceZone::query()->whereRaw('lower(code) = ?', [mb_strtolower($value)]);

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        if ($query->exists()) {
            $fail((string) __('geography.zone_code_taken'));
        }
    }
}
