<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

/**
 * Готовая публичная ссылка трекинга (FR-TRK-133).
 */
final readonly class TrackingLinkSnapshot
{
    public function __construct(
        public string $url,
        public string $expiresAt,
    ) {}
}
