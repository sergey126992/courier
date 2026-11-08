<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо уведомления сервиса (FR-NTF-251, FR-NTF-258).
 *
 * Тема и строки тела собираются слоем отправки из lang-шаблонов сервиса
 * и передаются готовыми: литеральных текстов в коде нет. В письме только
 * номер заказа, статус и ссылка трекинга, поэтому адрес, имя и телефон
 * получателя в шаблон не попадают даже случайно.
 *
 * Письмо не ставит себя в очередь: отправку и её повтор выполняет слой
 * отправки сервиса (FR-NTF-272).
 */
final class NotificationMail extends Mailable
{
    /**
     * @param array<string, string> $lines строки шаблона: subject, greeting, body, tracking, signature
     */
    public function __construct(public readonly array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lines['subject'] ?? '');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notifications.notification',
            with: ['lines' => $this->lines],
        );
    }
}
