<?php

declare(strict_types=1);

namespace App\Domain\Events;

use App\Domain\Events\Exceptions\EventTypeUnknown;

/**
 * Реестр типов событий — единственный источник перечня, владельцев и
 * обязательных полей payload (FR-NTF-111).
 *
 * Данные объявлены перечислением EventType, а прикладной код спрашивает
 * реестр: тип, которого нет в реестре, — ошибка контракта EVENT_TYPE_UNKNOWN.
 */
final class EventTypeRegistry
{
    /**
     * Версия контракта, которую получает каждое новое событие этапа.
     */
    public function __construct(
        private readonly EventSchemaVersion $schemaVersion = EventSchemaVersion::V1,
    ) {}

    /**
     * Перечень зарегистрированных типов в порядке объявления.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(
            static fn (EventType $type): string => $type->value,
            EventType::cases(),
        );
    }

    public function has(string $type): bool
    {
        return EventType::tryFrom($type) !== null;
    }

    /**
     * @throws EventTypeUnknown тип не зарегистрирован
     */
    public function get(string $type): EventType
    {
        return EventType::tryFrom($type) ?? throw EventTypeUnknown::make($type);
    }

    /**
     * @return list<string>
     *
     * @throws EventTypeUnknown тип не зарегистрирован
     */
    public function requiredPayloadFields(string $type): array
    {
        return $this->get($type)->requiredPayloadFields();
    }

    /**
     * @throws EventTypeUnknown тип не зарегистрирован
     */
    public function aggregateType(string $type): AggregateType
    {
        return $this->get($type)->aggregateType();
    }

    /**
     * Версия контракта, которую получает каждое новое событие этапа.
     */
    public function schemaVersion(): EventSchemaVersion
    {
        return $this->schemaVersion;
    }
}
