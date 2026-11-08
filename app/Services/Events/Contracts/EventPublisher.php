<?php

declare(strict_types=1);

namespace App\Services\Events\Contracts;

use App\Domain\Events\EventEnvelope;

/**
 * Порт передачи события потребителям (FR-NTF-121).
 *
 * Домен и операции зависят только от контракта: локальную шину можно
 * заменить брокером, не меняя домен и действия.
 */
interface EventPublisher
{
    /**
     * Передаёт событие всем зарегистрированным слушателям.
     *
     * Исключение означает, что событие принято не всеми: вызывающий фиксирует
     * неуспех и повторяет передачу по backoff этапа (FR-NTF-126).
     */
    public function publish(EventEnvelope $event): void;
}
