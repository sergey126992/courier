<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Источник эффективной строки предпочтений (FR-NTF-452).
 *
 * `user` — сохранённая настройка получателя, `default` — дефолт матрицы M11
 * для типа события, по которому пользователь ничего не сохранял.
 */
enum PreferenceSource: string
{
    case User = 'user';
    case Default = 'default';
}
