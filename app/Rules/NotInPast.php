<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Момент времени не в прошлом.
 *
 * Допуск на расхождение часов задаётся конфигурацией (FR-ORD-113): заявка
 * на пару минут назад считается нормальной, а вчерашняя — ошибкой.
 */
final class NotInPast implements ValidationRule
{
    public function __construct(
        private readonly int $toleranceMinutes,
    ) {}

    /**
     * Правило с допуском из конфигурации приложения.
     */
    public static function fromConfig(): self
    {
        return new self((int) config('orders.requested_at_tolerance_minutes'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $timezone = (string) config('app.timezone');

        try {
            $moment = CarbonImmutable::parse($value, $timezone);
        } catch (InvalidFormatException) {
            // Формат проверяет правило date: здесь только сравнение со временем.
            return;
        }

        $threshold = CarbonImmutable::now($timezone)->subMinutes($this->toleranceMinutes);

        if ($moment->lessThan($threshold)) {
            $fail((string) __('orders.errors.REQUESTED_AT_IN_PAST', ['minutes' => $this->toleranceMinutes]));
        }
    }
}
