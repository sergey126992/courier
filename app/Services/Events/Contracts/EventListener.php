<?php

declare(strict_types=1);

namespace App\Services\Events\Contracts;

use App\Domain\Events\EventEnvelope;

/**
 * Слушатель события (FR-NTF-122, FR-NTF-126).
 *
 * Порядок доставки не гарантируется, а повторная доставка допустима:
 * слушатель обязан дать один эффект при любом числе вызовов (FR-NTF-124, FR-NTF-125).
 */
interface EventListener
{
    /**
     * Обрабатывает событие; исключение означает отказ принять событие.
     */
    public function handle(EventEnvelope $event): void;

    /**
     * Стабильное имя обработчика для отметок Inbox (FR-NTF-131, FR-NTF-138).
     *
     * По паре «событие — имя» база решает, обработано ли событие именно этим
     * слушателем, поэтому имя задаётся явно и не меняется при рефакторинге:
     * производное от класса имя сломалось бы при переименовании.
     */
    public function handler(): string;
}
