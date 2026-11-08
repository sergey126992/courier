<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Получатель уведомления: роль матрицы и идентификатор пользователя (FR-NTF-241).
 *
 * Идентификатор приходит из события (`sender_id`, `courier_id`) или из
 * собственной базы сервиса для отмены заказа. Персональных данных здесь нет.
 */
final readonly class Recipient
{
    public function __construct(
        public RecipientRole $role,
        public int $userId,
    ) {}
}
