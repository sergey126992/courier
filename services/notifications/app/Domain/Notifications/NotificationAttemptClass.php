<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Маркер класса ошибки попытки отправки (FR-NTF-271, FR-NTF-273).
 *
 * В журнал попыток пишется именно класс причины, а не имя класса исключения:
 * полностью квалифицированное имя реализации — внутренняя деталь сервиса
 * и в данные попадать не должна.
 */
final class NotificationAttemptClass
{
    /**
     * Бизнес-причина: повтор не поможет (нет контакта, нет шаблона).
     */
    public const BUSINESS = 'business';

    /**
     * Инфраструктурный сбой: отправка повторяется по backoff.
     */
    public const INFRASTRUCTURE = 'infrastructure';

    /**
     * Программная ошибка: разбор без автоматического повтора.
     */
    public const PROGRAM = 'program';
}
