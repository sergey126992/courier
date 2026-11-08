<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\AggregateType;
use App\Domain\Events\EventType;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Запись события смены контактов получателя в Outbox (FR-NTF-231).
 *
 * Событие пишется в транзакции операции (регистрация, смена почты), поэтому
 * сервис оповещений получает адрес и язык вместе с изменением, а не читает
 * чужую базу (FR-NTF-231, FR-NTF-232). Поток выбирает транспорт по типу
 * события: это единственный контракт с персональными данными, и он уходит
 * в поток контактов, а не в поток заказов.
 *
 * correlation_id строки Outbox заполняет модель из контекста запроса
 * (FR-OPS-201). В журнал payload не попадает: email — персональные данные
 * (FR-NTF-235).
 */
final readonly class UserContactChangedOutbox
{
    /**
     * @param DateTimeInterface|null $occurredAt момент события; null — текущее время
     */
    public function record(User $user, ?DateTimeInterface $occurredAt = null): OutboxMessage
    {
        $eventId = (string) Str::uuid();
        $occurredAt ??= now();

        return OutboxMessage::query()->create([
            'message_id' => $eventId,
            'aggregate_type' => AggregateType::User->value,
            'aggregate_id' => (int) $user->getKey(),
            'event_type' => EventType::UserContactChanged->value,
            'payload' => [
                // event_id равен message_id строки Outbox (FR-NTF-223, ЧТЗ 8.3).
                'event_id' => $eventId,
                'user_id' => (int) $user->getKey(),
                'email' => (string) $user->email,
                'locale' => app()->getLocale(),
                'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
            ],
        ]);
    }
}
