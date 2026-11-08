<?php

declare(strict_types=1);

namespace App\Http\Problem;

/**
 * Коды ошибок API сервиса и их HTTP-статусы (ЧТЗ 8.5, FR-OPS-207).
 *
 * Ответ клиенту несёт только код и общий заголовок: внутренние сообщения
 * исключений, ответы провайдеров и персональные данные наружу не уходят.
 */
enum ErrorCode: string
{
    case InvalidServiceToken = 'INVALID_SERVICE_TOKEN';
    case NotFound = 'NOT_FOUND';
    case NotificationAlreadySent = 'NOTIFICATION_ALREADY_SENT';
    case ValidationFailed = 'VALIDATION_FAILED';
    case InternalError = 'INTERNAL_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case TelegramLinkTokenInvalid = 'TELEGRAM_LINK_TOKEN_INVALID';
    case TelegramLinkTokenExpired = 'TELEGRAM_LINK_TOKEN_EXPIRED';
    case TelegramBindingAbsent = 'TELEGRAM_BINDING_ABSENT';
    case TelegramChannelDisabled = 'TELEGRAM_CHANNEL_DISABLED';
    case TelegramWebhookSecretInvalid = 'TELEGRAM_WEBHOOK_SECRET_INVALID';
    case PreferenceInvalidEventType = 'PREFERENCE_INVALID_EVENT_TYPE';
    case PreferenceInvalidChannel = 'PREFERENCE_INVALID_CHANNEL';
    case PreferenceInvalidLocale = 'PREFERENCE_INVALID_LOCALE';
    case PreferenceInvalidQuietHours = 'PREFERENCE_INVALID_QUIET_HOURS';
    case PreferenceCriticalChannelRequired = 'PREFERENCE_CRITICAL_CHANNEL_REQUIRED';
    case PreferenceEventTypeNotAllowed = 'PREFERENCE_EVENT_TYPE_NOT_ALLOWED';
    case DlqEntryNotFound = 'DLQ_ENTRY_NOT_FOUND';
    case DlqEntryAlreadyResolved = 'DLQ_ENTRY_ALREADY_RESOLVED';
    case DeviceTokenInvalid = 'DEVICE_TOKEN_INVALID';
    case DeviceTokenUnknown = 'DEVICE_TOKEN_UNKNOWN';
    case MetricsUnavailable = 'METRICS_UNAVAILABLE';

    /**
     * HTTP-статус, который соответствует коду.
     */
    public function status(): int
    {
        return match ($this) {
            self::InvalidServiceToken => 401,
            self::NotFound => 404,
            self::NotificationAlreadySent,
            self::DlqEntryAlreadyResolved => 409,
            self::DlqEntryNotFound => 404,
            self::DeviceTokenUnknown => 404,
            self::ValidationFailed => 422,
            self::DeviceTokenInvalid => 422,
            self::ServiceUnavailable,
            self::MetricsUnavailable => 503,
            self::InternalError => 500,
            self::TelegramWebhookSecretInvalid => 403,
            self::TelegramLinkTokenInvalid => 409,
            self::TelegramLinkTokenExpired => 410,
            self::TelegramBindingAbsent => 409,
            self::TelegramChannelDisabled => 422,
            self::PreferenceInvalidEventType => 422,
            self::PreferenceInvalidChannel => 422,
            self::PreferenceInvalidLocale => 422,
            self::PreferenceInvalidQuietHours => 422,
            self::PreferenceCriticalChannelRequired => 422,
            self::PreferenceEventTypeNotAllowed => 422,
        };
    }

    /**
     * Заголовок проблемы для клиента: без внутренних деталей.
     */
    public function title(): string
    {
        return (string) __('notifications.problem.'.$this->value);
    }

    /**
     * URI типа проблемы по RFC 7807.
     */
    public function type(): string
    {
        return rtrim((string) config('notifications.api.problem_type_base'), '/').'/'.$this->value;
    }
}
