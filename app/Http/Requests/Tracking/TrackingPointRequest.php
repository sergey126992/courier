<?php

declare(strict_types=1);

namespace App\Http\Requests\Tracking;

use App\Domain\Geography\Coordinates;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Тело геоточки курьера и heartbeat (ЧТЗ 8.4).
 *
 * Набор полей общий: широта, долгота и необязательное время точки. Набор
 * полей закрыт — лишние поля отклоняются проверкой после основных правил,
 * чтобы клиент не считал, что они учтены.
 *
 * Класс не final: heartbeat переиспользует его через CourierLocationRequest.
 */
class TrackingPointRequest extends FormRequest
{
    /**
     * Разрешённые поля тела запроса.
     *
     * @var list<string>
     */
    private const FIELDS = ['latitude', 'longitude', 'recorded_at'];

    /**
     * Маршруты закрыты auth:sanctum; роли и владение проверяет контроллер.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90', 'decimal:0,6'],
            'longitude' => ['required', 'numeric', 'between:-180,180', 'decimal:0,6'],
            'recorded_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * Отклоняет поля, которых нет в контракте (ЧТЗ 8.4).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<string, mixed> $all */
                $all = $this->all();

                foreach (array_diff(array_keys($all), self::FIELDS) as $field) {
                    $validator->errors()->add((string) $field, (string) __('validation.prohibited', [
                        'attribute' => (string) $field,
                    ]));
                }
            },
        ];
    }

    /**
     * Координаты точки; диапазоны и точность уже проверены правилами.
     */
    public function coordinates(): Coordinates
    {
        return new Coordinates(
            (float) $this->validated('latitude'),
            (float) $this->validated('longitude'),
        );
    }

    /**
     * Время точки; null — операция подставит текущее время.
     */
    public function recordedAt(): ?DateTimeImmutable
    {
        $value = $this->validated('recorded_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, (string) config('app.timezone'))->toDateTimeImmutable();
    }
}
