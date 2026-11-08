<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Carbon;

/**
 * Отчёт о состоянии сервиса для внешнего мониторинга (FR-NTF-214).
 *
 * Содержит только факты: состояния зависимостей, лаг потребления и число
 * необработанных. Персональных данных, токенов, адресов и идентификаторов
 * пользователей в отчёте нет (FR-OPS-210).
 */
final readonly class HealthReport
{
    /**
     * Значение status в норме (ЧТЗ 8.6).
     */
    public const STATUS_OK = 'ok';

    /**
     * Значение status при сбое зависимости или превышении порога (ЧТЗ 8.6).
     */
    public const STATUS_DEGRADED = 'degraded';

    /**
     * @param array{database: string, redis: string, contracts: string} $checks
     * @param string|null $broker состояние брокера: добавляется только при транспорте rabbitmq
     */
    public function __construct(
        public string $status,
        public array $checks,
        public ?int $lagSeconds,
        public ?int $pending,
        public Carbon $generatedAt,
        public ?string $broker = null,
    ) {}

    /**
     * Сервис в норме: все зависимости доступны и пороги не превышены.
     */
    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /**
     * Состав ответа `/health` из ЧТЗ 8.6.
     *
     * @return array{status: string, checks: array<string, string>, lag_seconds: int|null, pending: int|null, generated_at: string}
     */
    public function toArray(): array
    {
        // Проверка брокера добавляется аддитивно: при транспорте redis состав
        // и порядок прежних полей не меняются (FR-NTF-548).
        $checks = $this->checks;

        if ($this->broker !== null) {
            $checks['broker'] = $this->broker;
        }

        return [
            'status' => $this->status,
            'checks' => $checks,
            'lag_seconds' => $this->lagSeconds,
            'pending' => $this->pending,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
