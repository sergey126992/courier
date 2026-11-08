<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Exceptions\AssignmentTransitionNotAllowed;

/**
 * Таблица допустимых переходов статусов назначения (ЧТЗ 5.2).
 *
 * Права роли здесь не проверяются: домен не знает о пользователях. Кто и при
 * каком условии выполняет переход, решает вызывающий слой; домен отвечает
 * только за допустимость пары статусов. Терминальные статусы (вручено,
 * не выполнено, отменено) переходов не имеют: продолжение — новая попытка.
 */
final class AssignmentTransitions
{
    /**
     * Разрешённые переходы: текущий статус → список целевых.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        AssignmentStatus::Assigned->value => [
            AssignmentStatus::Accepted->value,
            AssignmentStatus::Cancelled->value,
        ],
        AssignmentStatus::Accepted->value => [
            AssignmentStatus::PickedUp->value,
            AssignmentStatus::Cancelled->value,
        ],
        AssignmentStatus::PickedUp->value => [
            AssignmentStatus::Delivered->value,
            AssignmentStatus::Failed->value,
        ],
        AssignmentStatus::Delivered->value => [],
        AssignmentStatus::Failed->value => [],
        AssignmentStatus::Cancelled->value => [],
    ];

    /**
     * Целевые статусы, доступные из текущего.
     *
     * @return list<AssignmentStatus>
     */
    public static function targets(AssignmentStatus $from): array
    {
        return array_map(
            static fn (string $status): AssignmentStatus => AssignmentStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }

    /**
     * Разрешён ли переход.
     */
    public static function isAllowed(AssignmentStatus $from, AssignmentStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * @throws AssignmentTransitionNotAllowed переход не предусмотрен правилами
     */
    public static function assertAllowed(AssignmentStatus $from, AssignmentStatus $to): void
    {
        if (! self::isAllowed($from, $to)) {
            throw AssignmentTransitionNotAllowed::make($from, $to);
        }
    }
}
