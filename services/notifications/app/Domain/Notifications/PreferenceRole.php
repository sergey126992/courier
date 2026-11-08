<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Роль получателя, для которой выбираются предпочтения (FR-NTF-460, FR-UI-408).
 *
 * Отдельно от `RecipientRole`: автоматические уведомления получают только
 * отправитель и курьер, а экран настроек открывается всем ролям доступа.
 * Наборы типов событий по ролям задаются конфигурацией сервиса; перечисление
 * лишь называет роль в запросе и не дублирует наборы.
 */
enum PreferenceRole: string
{
    case Sender = 'sender';
    case Courier = 'courier';
    case Dispatcher = 'dispatcher';
    case Admin = 'admin';
}
