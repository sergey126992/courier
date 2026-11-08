<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Удаляет истёкшие ключи идемпотентности (FR-ORD-112).
 *
 * Срок хранения ключей ограничен, поэтому старые записи не копятся.
 */
final class PurgeExpiredIdempotencyKeys extends Command
{
    /**
     * @var string
     */
    protected $signature = 'orders:purge-idempotency-keys';

    /**
     * @var string
     */
    protected $description = 'Удаляет истёкшие ключи идемпотентности создания заказов';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()->where('expires_at', '<', now())->delete();

        $this->info(sprintf('Удалено истёкших ключей: %d.', $deleted));

        return self::SUCCESS;
    }
}
