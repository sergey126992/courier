<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * Сообщение потока событий в том виде, в каком его отдал транспорт.
 *
 * Поля записи читаются как карта «имя → значение»: адаптер не добавляет
 * и не переименовывает поля контракта (FR-NTF-209). Персональных данных
 * в сообщениях потока заказов нет.
 */
final readonly class StreamMessage
{
    /**
     * @param array<string, string> $fields поля записи потока
     */
    public function __construct(
        public string $id,
        public array $fields,
    ) {}

    /**
     * Строковое поле записи; отсутствующее — пустая строка.
     */
    public function field(string $name): string
    {
        return (string) ($this->fields[$name] ?? '');
    }
}
