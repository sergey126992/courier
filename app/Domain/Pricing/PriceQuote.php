<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Exceptions\BreakdownMismatch;
use App\Domain\Pricing\Exceptions\CurrencyMismatch;

/**
 * Результат расчёта стоимости и времени доставки.
 *
 * Итог обязан совпадать с суммой строк разбивки — расхождение считается дефектом.
 */
final readonly class PriceQuote
{
    /**
     * @param list<BreakdownLine> $lines
     *
     * @throws BreakdownMismatch сумма строк не совпала с итогом
     * @throws CurrencyMismatch строки и итог в разных валютах
     */
    public function __construct(
        private TransportType $transport,
        private ?LightVehicleKind $lightVehicleKind,
        private Distance $distance,
        private int $etaMinutes,
        private Money $total,
        private array $lines,
    ) {
        $this->assertLinesMatchTotal($this->lines, $this->total);
    }

    /**
     * Тип транспорта, для которого выполнен расчёт.
     */
    public function transport(): TransportType
    {
        return $this->transport;
    }

    /**
     * Вид лёгкого транспорта, если он применим.
     */
    public function lightVehicleKind(): ?LightVehicleKind
    {
        return $this->lightVehicleKind;
    }

    /**
     * Расстояние маршрута.
     */
    public function distance(): Distance
    {
        return $this->distance;
    }

    /**
     * Прогноз доставки в минутах.
     */
    public function etaMinutes(): int
    {
        return $this->etaMinutes;
    }

    /**
     * Итоговая сумма.
     */
    public function total(): Money
    {
        return $this->total;
    }

    /**
     * Строки разбивки, включая нулевые.
     *
     * @return list<BreakdownLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Строка разбивки по коду, если она есть в результате.
     */
    public function line(BreakdownCode $code): ?BreakdownLine
    {
        foreach ($this->lines as $line) {
            if ($line->code() === $code) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param list<BreakdownLine> $lines
     *
     * @throws BreakdownMismatch сумма строк не совпала с итогом
     * @throws CurrencyMismatch строки и итог в разных валютах
     */
    private function assertLinesMatchTotal(array $lines, Money $total): void
    {
        $sum = Money::fromMinor(0, $total->currency());

        foreach ($lines as $line) {
            if ($line->amount()->currency() !== $total->currency()) {
                throw CurrencyMismatch::forOperation(
                    'разбивка',
                    $total->currency(),
                    $line->amount()->currency(),
                );
            }

            $sum = $sum->plus($line->amount());
        }

        if (! $sum->equals($total)) {
            throw BreakdownMismatch::forTotals($sum, $total);
        }
    }
}
