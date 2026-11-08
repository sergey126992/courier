<?php

declare(strict_types=1);

namespace App\Actions\Geography;

use App\Models\Geography\AddressDirectoryEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Поиск по справочнику адресов для подсказок поля адреса.
 *
 * Действие только читает справочник: ищет действующие дома по подстроке
 * в улице, районе или номере дома без учёта регистра. Слишком короткий
 * запрос не обращается к базе: подсказки по одному символу бесполезны,
 * а запрос по ним дорог. Подпись «Улица, дом — район» собирается здесь,
 * поэтому шаблон не знает о формате адреса.
 */
final readonly class SearchAddressDirectory
{
    /** Минимальная длина запроса: короче подсказки не нужны. */
    private const MIN_QUERY_LENGTH = 2;

    /** Сколько подсказок максимум отдаётся за один поиск. */
    private const LIMIT = 8;

    /** Символ экранирования LIKE: пробрасывается параметром, чтобы PDO не спутал его с кавычкой SQL. */
    private const LIKE_ESCAPE = '\\';

    /**
     * Подсказки адреса по строке запроса.
     *
     * @return Collection<int, array{id: int, label: string, district: string, street: string, house: string}>
     */
    public function handle(string $query): Collection
    {
        $term = mb_strtolower(trim($query));

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return collect();
        }

        $pattern = '%'.$this->escapeLike($term).'%';

        return AddressDirectoryEntry::query()
            ->active()
            ->where(static function (Builder $builder) use ($pattern): void {
                // Подстрока без учёта регистра: lower() колонки с lower() строки.
                $builder->whereRaw('lower(street) LIKE ? ESCAPE ?', [$pattern, self::LIKE_ESCAPE])
                    ->orWhereRaw('lower(district) LIKE ? ESCAPE ?', [$pattern, self::LIKE_ESCAPE])
                    ->orWhereRaw('lower(house) LIKE ? ESCAPE ?', [$pattern, self::LIKE_ESCAPE]);
            })
            ->orderByRaw('lower(street)')
            ->orderByRaw('lower(house)')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (AddressDirectoryEntry $entry): array => [
                'id' => (int) $entry->id,
                'label' => $entry->label(),
                'district' => $entry->district,
                'street' => $entry->street,
                'house' => $entry->house,
            ])
            ->values();
    }

    /**
     * Экранирование спецсимволов LIKE.
     *
     * Обратная косая черта экранируется первой: иначе она экранировала бы
     * добавленные следом проценты и подчёркивания. Так `%` и `_` из запроса
     * ищутся буквально и не превращаются в шаблон.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
