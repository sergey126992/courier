<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Notifications\InboxStatus;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Inbox сервиса: «ровно один эффект» на сообщение потока (FR-NTF-261…266).
 *
 * Доставка — «хотя бы один раз»: потребитель может получить сообщение
 * повторно после сбоя до подтверждения. Защита от второго эффекта строится
 * на уникальной паре «сообщение — потребитель» в `inbox_messages`: отметка
 * записывается первой и в той же транзакции, что и эффект, поэтому сбой
 * эффекта откатывает и отметку, а повтор обработает сообщение заново
 * (FR-NTF-261, FR-NTF-265).
 *
 * Параллельную обработку разрешает уникальный индекс базы: вставка идёт
 * через ON CONFLICT DO NOTHING, проигравший получает ноль строк и пропускает
 * обработку без ошибки и без перевода транзакции в состояние ошибки
 * (FR-NTF-262…264).
 */
final class EventInbox
{
    /**
     * Выполняет эффект один раз на пару «сообщение — потребитель».
     *
     * @param Closure(): void $effect побочный эффект обработчика
     * @return bool true — эффект выполнен; false — сообщение уже обработано
     */
    public function process(string $stream, string $consumer, string $messageId, Closure $effect): bool
    {
        return DB::transaction(function () use ($stream, $consumer, $messageId, $effect): bool {
            $now = now()->utc();

            $captured = DB::table('inbox_messages')->insertOrIgnore([
                'message_id' => $messageId,
                'consumer' => $consumer,
                'stream' => $stream,
                'status' => InboxStatus::Processed->value,
                'error_code' => null,
                'received_at' => $now->format('Y-m-d H:i:sP'),
                'processed_at' => $now->format('Y-m-d H:i:sP'),
                'created_at' => $now->format('Y-m-d H:i:sP'),
            ]);

            if ($captured === 0) {
                // Ключ уже занят: повторная доставка или параллельный потребитель.
                return false;
            }

            $effect();

            return true;
        });
    }

    /**
     * Отмечает сообщение недействительным: эффекта нет, поток не блокируется.
     *
     * @param string $errorCode код причины из ЧТЗ 8.5
     * @return bool true — отметка записана; false — сообщение уже отмечено
     */
    public function recordInvalid(string $stream, string $consumer, string $messageId, string $errorCode): bool
    {
        $now = now()->utc();

        return DB::table('inbox_messages')->insertOrIgnore([
            'message_id' => $messageId,
            'consumer' => $consumer,
            'stream' => $stream,
            'status' => InboxStatus::Invalid->value,
            'error_code' => $errorCode,
            'received_at' => $now->format('Y-m-d H:i:sP'),
            'processed_at' => null,
            'created_at' => $now->format('Y-m-d H:i:sP'),
        ]) === 1;
    }
}
