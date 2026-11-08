<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Reporting\ReportPeriod;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Загрузка курьеров: сумма пересечений смен с периодом (FR-RPT-119, FR-RPT-120).
 *
 * Второй и последний SQL отчёта (FR-RPT-161). Запрос отдаёт только секунды
 * смен: число активных курьеров считает сводный запрос по вручениям
 * (FR-RPT-117). Суммирует база, PHP лишь переводит скаляр в доменный объект
 * (FR-RPT-122). Открытая смена обрезается правой границей периода, смена,
 * начавшаяся до периода, — левой; смены вне периода не влияют, неположительные
 * пересечения отбрасываются. Фильтра по зоне и по вручениям нет (FR-RPT-120).
 *
 * Границы периода передаются строками с явным смещением: Laravel форматирует
 * DateTime без смещения, и границы «поехали» бы относительно сессии БД
 * (FR-RPT-147, FR-RPT-149).
 */
final readonly class CourierLoadQuery
{
    /**
     * Секунды пересечений смен с периодом: сумма разностей границ.
     */
    private const SQL = <<<'SQL'
        WITH params AS (
            SELECT CAST(? AS timestamptz) AS period_from,
                   CAST(? AS timestamptz) AS period_to
        )
        SELECT CAST(
                   COALESCE(
                       SUM(
                           GREATEST(
                               EXTRACT(EPOCH FROM (
                                   least(COALESCE(s.ended_at, p.period_to), p.period_to)
                                   - greatest(s.started_at, p.period_from)
                               )),
                               0
                           )
                       ),
                       0
                   ) AS bigint
               ) AS shift_seconds
        FROM courier_shifts s
        CROSS JOIN params p
        WHERE s.started_at < p.period_to
          AND (s.ended_at IS NULL OR s.ended_at > p.period_from)
        SQL;

    /**
     * Секунды смен, пересекающихся с периодом; при отсутствии смен — ноль.
     */
    public function fetch(ReportPeriod $period): int
    {
        $row = DB::selectOne($this->sql(), $this->bindings($period));

        if ($row === null) {
            return 0;
        }

        $data = (array) $row;

        return (int) $data['shift_seconds'];
    }

    /**
     * Текст запроса смен — тот же, что выполняет fetch (FR-RPT-166).
     */
    public function sql(): string
    {
        return self::SQL;
    }

    /**
     * Биндинги запроса смен в порядке плейсхолдеров (FR-RPT-166).
     *
     * @return array{0: string, 1: string}
     */
    public function bindings(ReportPeriod $period): array
    {
        return [
            $this->boundary($period->from()),
            $this->boundary($period->to()),
        ];
    }

    /**
     * Граница периода строкой с явным смещением (FR-RPT-147).
     */
    private function boundary(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d H:i:sP');
    }
}
