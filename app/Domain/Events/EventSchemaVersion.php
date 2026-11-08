<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Версия контракта события (FR-NTF-112).
 *
 * Несовместимое изменение смысла вводит новый тип события с суффиксом `.v2`,
 * поэтому уже записанные строки Outbox не переинтерпретируются.
 */
enum EventSchemaVersion: string
{
    case V1 = 'v1';
}
