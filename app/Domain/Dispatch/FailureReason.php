<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

/**
 * Причина неудачной попытки вручения (FR-DSP-144).
 *
 * Перечень закрыт: произвольный текст допускается только в комментарии к
 * причине, чтобы отчётность не разваливалась на свободные формулировки.
 */
enum FailureReason: string
{
    case RecipientUnavailable = 'recipient_unavailable';
    case RecipientRefused = 'recipient_refused';
    case WrongAddress = 'wrong_address';
    case AccessDenied = 'access_denied';
    case Other = 'other';

    /**
     * Человекочитаемое название причины.
     */
    public function label(): string
    {
        return match ($this) {
            self::RecipientUnavailable => 'Получатель недоступен',
            self::RecipientRefused => 'Получатель отказался',
            self::WrongAddress => 'Неверный адрес',
            self::AccessDenied => 'Доступ к адресу закрыт',
            self::Other => 'Другая причина',
        };
    }
}
