<?php

declare(strict_types=1);

namespace App\Services\Notifications\Telegram;

use App\Domain\Notifications\ChannelBindingStatus;
use Illuminate\Support\Carbon;

/**
 * Результат выпуска токена привязки Telegram (FR-NTF-411).
 *
 * Открытое значение токена существует только внутри deep-link и
 * возвращается вызывающему ровно один раз (FR-NTF-420): в базе остаётся
 * хеш, в журнале и последующих ответах API значения нет.
 */
final readonly class TelegramLinkStart
{
    public function __construct(
        public string $deepLink,
        public Carbon $expiresAt,
        public ChannelBindingStatus $status,
    ) {}
}
