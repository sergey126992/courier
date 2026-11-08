<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

/**
 * Публичный снимок трекинга: только поля из белого списка (FR-TRK-134).
 *
 * Объект неизменяемый и состоит из скаляров, поэтому его можно класть в кэш:
 * адреса, координат, имени, телефона, цены и номера заказа здесь нет.
 */
final readonly class PublicTrackingSnapshot
{
    public function __construct(
        public string $status,
        public string $statusLabel,
        public int $etaMinutes,
        public ?string $etaAt,
        public ?string $district,
        public string $updatedAt,
    ) {}

    /**
     * Состав публичного ответа: ключи фиксированы и других полей не бывает.
     *
     * @return array{status: string, status_label: string, eta_minutes: int, eta_at: string|null, district: string|null, updated_at: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'eta_minutes' => $this->etaMinutes,
            'eta_at' => $this->etaAt,
            'district' => $this->district,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Восстановление из кэша.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $etaAt = $data['eta_at'] ?? null;
        $district = $data['district'] ?? null;

        return new self(
            status: (string) ($data['status'] ?? ''),
            statusLabel: (string) ($data['status_label'] ?? ''),
            etaMinutes: (int) ($data['eta_minutes'] ?? 0),
            etaAt: is_string($etaAt) ? $etaAt : null,
            district: is_string($district) ? $district : null,
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }
}
