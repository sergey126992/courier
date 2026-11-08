<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\TransportType;

/**
 * Разрез отчёта по типам транспорта (FR-RPT-131…137).
 *
 * Набор строк задан доменным списком `TransportType::cases()`, поэтому в
 * отчёте всегда ровно три строки в фиксированном порядке. Отсутствующий в
 * данных тип получает нули, а доля и место строки приходят из SQL: домен
 * только хранит строки и отдаёт их значения (FR-RPT-134, FR-RPT-135).
 */
final readonly class TransportBreakdown
{
    /**
     * @param list<TransportBreakdownRow> $rows строки в доменном порядке
     */
    private function __construct(private array $rows) {}

    /**
     * Собирает разрез в доменном порядке: отсутствующие типы получают нули.
     *
     * @param iterable<TransportBreakdownRow> $rows
     */
    public static function fromRows(iterable $rows): self
    {
        $byType = [];

        foreach ($rows as $row) {
            $byType[$row->transportType->value] = $row;
        }

        $ordered = [];

        foreach (TransportType::cases() as $type) {
            $ordered[] = $byType[$type->value] ?? new TransportBreakdownRow($type);
        }

        return new self($ordered);
    }

    /**
     * Строки разреза в доменном порядке (FR-RPT-133).
     *
     * @return list<TransportBreakdownRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
