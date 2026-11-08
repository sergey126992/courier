<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Доступ к административным страницам уведомлений (ЧТЗ 6, 8.2).
 *
 * Журнал необработанных, повтор и страница симулятора push открыты только
 * роли «администратор» (FR-NTF-293). Новых abilities токенов политика
 * не вводит: проверка выполняется Gate `notifications:manage`, а правило
 * роли живёт в домене.
 */
final class NotificationAdminPolicy
{
    public function view(User $actor): bool
    {
        return $actor->role->canAdministerNotifications();
    }
}
