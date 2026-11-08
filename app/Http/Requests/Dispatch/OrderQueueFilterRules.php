<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Domain\Identity\TransportType;
use App\Http\Requests\Order\OrderFilterRules;
use Illuminate\Validation\Rule;

/**
 * Правила фильтров очереди диспетчера (FR-UI-132, FR-UI-195).
 *
 * Период, размер порции и курсор берутся из общего набора OrderFilterRules:
 * формат дат и предел страницы не расходятся с API и списком отправителя.
 * Зона и тип транспорта — фильтры только очереди, поэтому объявлены здесь.
 */
final class OrderQueueFilterRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $base = OrderFilterRules::rules();

        return [
            'from' => self::nullable($base['from']),
            'to' => self::nullable($base['to']),
            'limit' => $base['limit'],
            'cursor' => $base['cursor'],
            // Зона фильтра — значение справочника, а не произвольное число.
            'zone_id' => ['nullable', 'integer', Rule::exists('service_zones', 'id')],
            'transport' => ['nullable', 'string', Rule::enum(TransportType::class)],
        ];
    }

    /**
     * Пустой период означает отсутствие фильтра, а не ошибку формата.
     *
     * @param array<int, mixed> $rules
     * @return array<int, mixed>
     */
    private static function nullable(array $rules): array
    {
        return array_values(array_map(
            static fn (mixed $rule): mixed => $rule === 'sometimes' ? 'nullable' : $rule,
            $rules,
        ));
    }
}
