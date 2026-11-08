<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Приведение моментов времени к UTC при записи (ЧТЗ 7, п. 5).
 *
 * Колонки объявлены как timestamptz, а часовой пояс приложения может быть не
 * UTC. Eloquent форматирует значение как «местные» часы, поэтому без
 * приведения в timestamptz попало бы смещённое время, и сравнения моментов
 * (готовность к публикации, время отправки) стали бы неверными.
 *
 * Приведение выполняется при присвоении атрибута: к моменту сохранения
 * значение уже отформатировано в строку без часового пояса, и исходный
 * момент восстановить нельзя. Перечень атрибутов задаёт модель.
 */
trait ConvertsTimestampsToUtc
{
    /**
     * Атрибуты-моменты времени, которые хранятся в UTC.
     *
     * @return list<string>
     */
    abstract protected function utcAttributes(): array;

    /**
     * Присваивает значение, предварительно приводя момент времени к UTC.
     *
     * @param mixed $key
     * @param mixed $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        if ($value !== null && in_array($key, $this->utcAttributes(), true)) {
            $value = $value instanceof DateTimeInterface
                ? Carbon::createFromInterface($value)->utc()
                : Carbon::parse((string) $value)->utc();
        }

        return parent::setAttribute($key, $value);
    }
}
