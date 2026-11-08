<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inbox: «ровно один эффект» на слушателя (FR-NTF-131…138).
 *
 * Доставка событий — «хотя бы один раз»: слушатель может быть вызван повторно
 * после сбоя публикатора или воркера. Защита от второго эффекта строится на
 * уникальной паре «событие — обработчик» в processed_messages: отметка
 * записывается первой и в той же транзакции, что и эффект (FR-NTF-132).
 *
 * Порядок строгий и другого нет: сначала захват ключа вставкой, затем эффект.
 * Поэтому отметка не опережает эффект и не остаётся после отката: сбой
 * замыкания откатывает транзакцию целиком, и повторная доставка обработает
 * событие заново (FR-NTF-135, FR-NTF-136).
 *
 * Параллельную обработку разрешает уникальный индекс базы: вставка идёт через
 * ON CONFLICT DO NOTHING, поэтому проигравший получает ноль вставленных строк
 * и пропускает обработку без ошибки и без перевода транзакции в состояние
 * ошибки (FR-NTF-134).
 */
final class Inbox
{
    /**
     * Таблица отметок обработанных событий.
     */
    private const TABLE = 'processed_messages';

    /**
     * Выполняет эффект один раз на пару «событие — обработчик».
     *
     * Имя обработчика задаёт слушатель и не меняется при рефакторинге: по нему
     * база решает, обработано ли событие именно этим слушателем (FR-NTF-138).
     *
     * @param string $handler стабильное имя обработчика
     * @param Closure(EventEnvelope): void $effect побочный эффект слушателя
     *
     * @throws InvalidArgumentException имя обработчика пустое
     */
    public function process(string $handler, EventEnvelope $event, Closure $effect): InboxResult
    {
        if (trim($handler) === '') {
            throw new InvalidArgumentException('Имя обработчика Inbox не может быть пустым.');
        }

        $result = InboxResult::Skipped;

        DB::transaction(function () use ($handler, $event, $effect, &$result): void {
            $now = now();

            $captured = DB::table(self::TABLE)->insertOrIgnore([
                'message_id' => $event->messageId,
                'handler' => $handler,
                // processed_at — timestamptz: момент передаётся со смещением UTC,
                // чтобы не зависеть от часового пояса сессии базы (ЧТЗ 7, п. 4).
                'processed_at' => $now->utc()->format('Y-m-d H:i:sP'),
                // created_at ведётся приложением в часовом поясе приложения:
                // по этой колонке считается срок хранения при очистке.
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);

            if ($captured === 0) {
                // Ключ уже занят: повторная доставка или параллельный воркер.
                return;
            }

            $effect($event);

            $result = InboxResult::Processed;
        });

        return $result;
    }
}
