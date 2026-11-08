<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * Источник остатка пути в ответе трекинга (FR-TRK-123, FR-TRK-124).
 *
 * computed — остаток посчитан по последней позиции и текущим условиям;
 * planned — остаток не вычислялся: позиции нет, назначение неактивно,
 * координаты недоступны или провайдер расстояний не ответил.
 */
enum EtaSource: string
{
    case Computed = 'computed';
    case Planned = 'planned';
}
