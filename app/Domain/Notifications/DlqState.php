<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Состояние записи очереди необработанных (ЧТЗ 5.2, FR-NTF-507).
 *
 * Нужно фильтру и подписям административной страницы «Сервис оповещений»:
 * состояния приходят из API сервиса, монолит их не меняет. Тон бейджа и
 * подпись задаёт экран, домен хранит только набор значений и правило повтора.
 */
enum DlqState: string
{
    case Open = 'open';
    case Replayed = 'replayed';
    case Resolved = 'resolved';

    /**
     * Человекочитаемое название состояния.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ждёт разбора',
            self::Replayed => 'Повтор поставлен',
            self::Resolved => 'Разобрана',
        };
    }

    /**
     * Повтор разрешён только для записи, которая ждёт разбора.
     */
    public function canReplay(): bool
    {
        return $this === self::Open;
    }

    /**
     * Тон бейджа `<x-app.status-badge>`: слово важнее цвета (FR-UI-176).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'error',
            self::Replayed => 'queued',
            self::Resolved => 'sent',
        };
    }
}
