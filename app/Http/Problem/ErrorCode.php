<?php

declare(strict_types=1);

namespace App\Http\Problem;

/**
 * Машиночитаемые коды ошибок API и их HTTP-статусы.
 *
 * Тексты заголовка и пояснения живут в lang/ru/errors.php, а не в коде.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case RateLimited = 'RATE_LIMITED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case ParcelTooHeavyForTransport = 'PARCEL_TOO_HEAVY_FOR_TRANSPORT';
    case ParcelOutsideDeliveryRadius = 'PARCEL_OUTSIDE_DELIVERY_RADIUS';
    case UnsupportedTransport = 'UNSUPPORTED_TRANSPORT';
    case AddressOutsideServiceArea = 'ADDRESS_OUTSIDE_SERVICE_AREA';
    case DistanceProviderUnavailable = 'DISTANCE_PROVIDER_UNAVAILABLE';
    case IdempotencyKeyConflict = 'IDEMPOTENCY_KEY_CONFLICT';
    case IdempotencyKeyInProgress = 'IDEMPOTENCY_KEY_IN_PROGRESS';
    case OrderStatusTransitionNotAllowed = 'ORDER_STATUS_TRANSITION_NOT_ALLOWED';
    case CourierInactive = 'COURIER_INACTIVE';
    case CourierShiftAlreadyOpen = 'COURIER_SHIFT_ALREADY_OPEN';
    case CourierShiftOverlap = 'COURIER_SHIFT_OVERLAP';
    case CourierShiftNotOpen = 'COURIER_SHIFT_NOT_OPEN';
    case ShiftHasActiveAssignment = 'SHIFT_HAS_ACTIVE_ASSIGNMENT';
    case CourierNotAvailable = 'COURIER_NOT_AVAILABLE';
    case NoCourierAvailable = 'NO_COURIER_AVAILABLE';
    case CourierTransportMismatch = 'COURIER_TRANSPORT_MISMATCH';
    case CourierZoneMismatch = 'COURIER_ZONE_MISMATCH';
    case AssignmentNotActive = 'ASSIGNMENT_NOT_ACTIVE';
    case TrackingPointOutOfSequence = 'TRACKING_POINT_OUT_OF_SEQUENCE';
    case ReportPeriodTooLong = 'REPORT_PERIOD_TOO_LONG';
    case ReportCurrencyMismatch = 'REPORT_CURRENCY_MISMATCH';
    case NotificationAlreadySent = 'NOTIFICATION_ALREADY_SENT';
    case NotificationServiceUnavailable = 'NOTIFICATION_SERVICE_UNAVAILABLE';
    case DlqEntryNotFound = 'DLQ_ENTRY_NOT_FOUND';
    case DlqEntryAlreadyResolved = 'DLQ_ENTRY_ALREADY_RESOLVED';
    case InternalError = 'INTERNAL_ERROR';

    /**
     * HTTP-статус, который соответствует коду.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::ParcelTooHeavyForTransport,
            self::ParcelOutsideDeliveryRadius,
            self::UnsupportedTransport,
            self::AddressOutsideServiceArea,
            self::IdempotencyKeyConflict,
            self::CourierTransportMismatch,
            self::CourierZoneMismatch,
            self::TrackingPointOutOfSequence,
            self::ReportPeriodTooLong => 422,
            self::InvalidCredentials => 401,
            self::EmailNotVerified, self::Forbidden, self::InvalidSignature => 403,
            self::RateLimited => 429,
            // Конфликт состояния: запрос с этим ключом уже выполняется, статус не допускает
            // действие или смена курьера находится в неподходящем состоянии.
            self::IdempotencyKeyInProgress,
            self::OrderStatusTransitionNotAllowed,
            self::CourierInactive,
            self::CourierShiftAlreadyOpen,
            self::CourierShiftOverlap,
            self::CourierShiftNotOpen,
            self::ShiftHasActiveAssignment,
            self::CourierNotAvailable,
            self::NoCourierAvailable,
            self::AssignmentNotActive,
            self::ReportCurrencyMismatch,
            self::NotificationAlreadySent,
            self::DlqEntryAlreadyResolved => 409,
            self::DlqEntryNotFound => 404,
            self::NotFound => 404,
            self::InternalError => 500,
            // Внешний провайдер недоступен: это не ошибка клиента, повтор возможен позже.
            self::DistanceProviderUnavailable,
            self::NotificationServiceUnavailable => 503,
        };
    }

    /**
     * Заголовок проблемы для клиента.
     */
    public function title(): string
    {
        return (string) __('errors.codes.'.$this->value.'.title');
    }

    /**
     * Пояснение проблемы для клиента.
     */
    public function detail(): string
    {
        return (string) __('errors.codes.'.$this->value.'.detail');
    }

    /**
     * URI типа проблемы по RFC 7807.
     */
    public function type(): string
    {
        return rtrim((string) config('api.problem_type_base'), '/').'/'.$this->value;
    }
}
