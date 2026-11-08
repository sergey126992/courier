<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Domain\Notifications\Exceptions\NotificationRecipientUnknown;
use App\Domain\Notifications\NotificationTemplate;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Services\Notifications\NotificationMessageContext;
use App\Services\Notifications\NotificationTexts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Канал email: письмо сервиса через SMTP/Mailpit (FR-NTF-251, FR-NTF-252).
 *
 * Адрес берётся из `recipient_contacts` в момент отправки: смена почты
 * в потоке контактов применяется к ещё не отправленным уведомлениям
 * (FR-NTF-233). Отсутствие адреса — бизнес-причина
 * `NOTIFICATION_RECIPIENT_UNKNOWN`: повтор не поможет (FR-NTF-236).
 *
 * Адрес не попадает ни в тему, ни в тело: в письме только номер заказа,
 * статус и ссылка трекинга (FR-NTF-251). Локаль письма — локаль контакта
 * с фолбэком на язык текстов сервиса (FR-NTF-247).
 */
final class EmailChannel
{
    public function __construct(
        private readonly NotificationTexts $texts,
        private readonly NotificationMessageContext $context,
    ) {}

    /**
     * @throws NotificationRecipientUnknown у получателя нет контакта с адресом
     */
    public function send(Notification $notification, NotificationTemplate $template): void
    {
        $contact = DB::table('recipient_contacts')
            ->where('user_id', $notification->recipient_id)
            ->first(['email', 'locale']);

        if ($contact === null) {
            throw NotificationRecipientUnknown::make((string) $notification->recipient_role);
        }

        $address = trim((string) $contact->email);

        if ($address === '') {
            throw NotificationRecipientUnknown::make((string) $notification->recipient_role);
        }

        $locale = is_string($contact->locale) ? $contact->locale : null;

        $lines = $this->texts->lines(
            $template,
            $locale,
            $this->context->replacements($notification),
        );

        Mail::to($address)->send(new NotificationMail($lines));
    }
}
