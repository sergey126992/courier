<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Доступ к отчёту по заказам, выручке и загрузке курьеров.
 *
 * Отчёт — административный инструмент (FR-RPT-103…105): правило роли живёт
 * в домене, а политика только переводит его в ответ «да/нет». Проверка
 * выполняется Gate `reports:read`, в контроллере роль не проверяется.
 */
final class ReportPolicy
{
    /**
     * Может ли пользователь смотреть отчёт целиком.
     *
     * Ролям без права отчёт не фильтруется: они получают отказ целиком.
     */
    public function view(User $actor): bool
    {
        return $actor->role->canViewReports();
    }
}
