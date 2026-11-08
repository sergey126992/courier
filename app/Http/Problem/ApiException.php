<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use RuntimeException;

/**
 * Исключение приложения, которое сразу несёт код ошибки API.
 *
 * Путь HTTP не знает о конкретных причинах отказа, а обработчик исключений
 * превращает его в единый ответ application/problem+json.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param list<array{field: string, message: string}> $details
     * @param int|null $retryAfter секунды до следующей попытки (заголовок Retry-After)
     * @param string|null $detail пояснение с данными запроса вместо общего текста кода
     */
    private function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($errorCode->value, $errorCode->status());
    }

    public static function invalidCredentials(): self
    {
        return new self(ErrorCode::InvalidCredentials);
    }

    public static function emailNotVerified(): self
    {
        return new self(ErrorCode::EmailNotVerified);
    }

    public static function forbidden(): self
    {
        return new self(ErrorCode::Forbidden);
    }

    public static function notFound(): self
    {
        return new self(ErrorCode::NotFound);
    }

    public static function invalidSignature(): self
    {
        return new self(ErrorCode::InvalidSignature);
    }

    /**
     * Некорректный или отсутствующий ключ идемпотентности (FR-ORD-111).
     */
    public static function invalidIdempotencyKey(int $minLength, int $maxLength): self
    {
        return new self(ErrorCode::ValidationFailed, [[
            'field' => 'Idempotency-Key',
            'message' => (string) __('orders.errors.IDEMPOTENCY_KEY_INVALID', [
                'min' => $minLength,
                'max' => $maxLength,
            ]),
        ]]);
    }

    /**
     * Ключ идемпотентности уже использован с другим телом запроса (FR-ORD-109).
     */
    public static function idempotencyKeyConflict(): self
    {
        return new self(ErrorCode::IdempotencyKeyConflict);
    }

    /**
     * Запрос с этим ключом ещё выполняется: повтор отклоняется конфликтом состояния (FR-ORD-110).
     */
    public static function idempotencyKeyInProgress(): self
    {
        return new self(ErrorCode::IdempotencyKeyInProgress);
    }

    /**
     * Переход статуса запрещён: в деталях текущий статус заказа (FR-ORD-123).
     */
    public static function orderStatusTransitionNotAllowed(OrderStatus $from, OrderStatus $to): self
    {
        return new self(ErrorCode::OrderStatusTransitionNotAllowed, [[
            'field' => 'status',
            'message' => (string) __('orders.errors.ORDER_STATUS_TRANSITION_NOT_ALLOWED', [
                'current' => $from->label(),
                'target' => $to->label(),
            ]),
        ]]);
    }

    /**
     * Курьер с неактивным профилем не открывает смену (FR-DSP-105).
     */
    public static function courierInactive(): self
    {
        return new self(ErrorCode::CourierInactive);
    }

    /**
     * У курьера уже есть открытая смена (FR-DSP-102).
     */
    public static function courierShiftAlreadyOpen(): self
    {
        return new self(ErrorCode::CourierShiftAlreadyOpen);
    }

    /**
     * Новая смена пересекается с существующей (FR-DSP-103).
     */
    public static function courierShiftOverlap(): self
    {
        return new self(ErrorCode::CourierShiftOverlap);
    }

    /**
     * Смена не открыта: закрывать нечего или она уже закрыта (FR-DSP-104).
     */
    public static function courierShiftNotOpen(): self
    {
        return new self(ErrorCode::CourierShiftNotOpen);
    }

    /**
     * Смену нельзя закрыть, пока у курьера есть активное назначение (FR-DSP-106).
     */
    public static function shiftHasActiveAssignment(): self
    {
        return new self(ErrorCode::ShiftHasActiveAssignment);
    }

    /**
     * Указанный курьер недоступен: нет открытой смены, профиль неактивен
     * или у курьера уже есть активное назначение (FR-DSP-136).
     */
    public static function courierNotAvailable(): self
    {
        return new self(ErrorCode::CourierNotAvailable);
    }

    /**
     * Подбор не нашёл ни одного кандидата (FR-DSP-136).
     */
    public static function noCourierAvailable(): self
    {
        return new self(ErrorCode::NoCourierAvailable);
    }

    /**
     * Тип транспорта указанного курьера не совпадает с заказом (FR-DSP-136).
     */
    public static function courierTransportMismatch(): self
    {
        return new self(ErrorCode::CourierTransportMismatch, [[
            'field' => 'courier_id',
            'message' => (string) __('dispatch.errors.COURIER_TRANSPORT_MISMATCH'),
        ]]);
    }

    /**
     * Зона смены указанного курьера не совпадает с зоной заказа (FR-DSP-136).
     */
    public static function courierZoneMismatch(): self
    {
        return new self(ErrorCode::CourierZoneMismatch, [[
            'field' => 'courier_id',
            'message' => (string) __('dispatch.errors.COURIER_ZONE_MISMATCH'),
        ]]);
    }

    /**
     * Назначение завершено, отменено или не в рабочем статусе (FR-DSP-147).
     */
    public static function assignmentNotActive(): self
    {
        return new self(ErrorCode::AssignmentNotActive);
    }

    /**
     * Время точки не больше предыдущей точки назначения (FR-TRK-105).
     */
    public static function trackingPointOutOfSequence(): self
    {
        return new self(ErrorCode::TrackingPointOutOfSequence, [[
            'field' => 'recorded_at',
            'message' => (string) __('tracking.errors.TRACKING_POINT_OUT_OF_SEQUENCE'),
        ]]);
    }

    /**
     * Время точки из будущего сверх допуска: ошибка данных, а не состояния (FR-TRK-104).
     *
     * Отдельного кода ЧТЗ не вводит, поэтому используется VALIDATION_FAILED
     * с указанием поля recorded_at: причина исправляется в теле запроса,
     * в отличие от конфликтов состояния назначения и смены.
     */
    public static function trackingPointInFuture(int $toleranceMinutes): self
    {
        return new self(ErrorCode::ValidationFailed, [[
            'field' => 'recorded_at',
            'message' => (string) __('tracking.errors.TRACKING_POINT_IN_FUTURE', [
                'minutes' => $toleranceMinutes,
            ]),
        ]]);
    }

    /**
     * Окончание смены не позже её начала: данные не проходят проверку.
     */
    public static function shiftPeriodInvalid(): self
    {
        return new self(ErrorCode::ValidationFailed, [[
            'field' => 'ended_at',
            'message' => (string) __('dispatch.errors.INVALID_SHIFT_PERIOD'),
        ]]);
    }

    /**
     * Превышен предел попыток: клиенту сообщается, когда повторить запрос.
     */
    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(ErrorCode::RateLimited, [], max(1, $retryAfterSeconds));
    }

    /**
     * Посылка тяжелее допустимого для транспорта: в деталях поле, предел и транспорт.
     */
    public static function parcelTooHeavyForTransport(int $maxWeightGrams, TransportType $transport): self
    {
        return new self(ErrorCode::ParcelTooHeavyForTransport, [[
            'field' => 'parcel.weight_grams',
            'message' => (string) __('pricing.errors.PARCEL_TOO_HEAVY_FOR_TRANSPORT', [
                'max' => $maxWeightGrams,
                'transport' => $transport->label(),
            ]),
        ]]);
    }

    /**
     * Адрес вне радиуса обслуживания транспорта: в деталях поле, предел и транспорт.
     */
    public static function parcelOutsideDeliveryRadius(float $maxRadiusKm, TransportType $transport): self
    {
        $limit = rtrim(rtrim(number_format($maxRadiusKm, 2, '.', ''), '0'), '.');

        return new self(ErrorCode::ParcelOutsideDeliveryRadius, [[
            'field' => 'distance_km',
            'message' => (string) __('pricing.errors.PARCEL_OUTSIDE_DELIVERY_RADIUS', [
                'max' => $limit,
                'transport' => $transport->label(),
            ]),
        ]]);
    }

    /**
     * Адрес вне зон обслуживания: в деталях поле адреса и понятное пояснение.
     *
     * @param string $addressField имя поля запроса: pickup или dropoff
     */
    public static function addressOutsideServiceArea(string $addressField): self
    {
        return new self(ErrorCode::AddressOutsideServiceArea, [[
            'field' => $addressField,
            'message' => (string) __('geography.errors.ADDRESS_OUTSIDE_SERVICE_AREA', [
                'address' => $addressField === 'dropoff' ? 'доставки' : 'забора',
            ]),
        ]]);
    }

    /**
     * Провайдер расстояний недоступен: клиенту сообщается только код и общий статус.
     *
     * Внутренние подробности (адреса, причина сбоя) в ответ не попадают.
     */
    public static function distanceProviderUnavailable(): self
    {
        return new self(ErrorCode::DistanceProviderUnavailable);
    }

    /**
     * Транспорт не поддерживается расчётом: в деталях поле и перечень допустимых значений.
     */
    public static function unsupportedTransport(TransportType $transport): self
    {
        return new self(ErrorCode::UnsupportedTransport, [[
            'field' => 'transport',
            'message' => (string) __('pricing.errors.UNSUPPORTED_TRANSPORT', [
                'transport' => $transport->label(),
                'supported' => implode(', ', array_map(
                    static fn (TransportType $case): string => $case->value,
                    TransportType::cases(),
                )),
            ]),
        ]]);
    }

    /**
     * Период отчёта длиннее допустимого: в пояснении предел из конфигурации (FR-RPT-192).
     *
     * Текст одинаков в поле details и в общем пояснении ответа, чтобы клиент
     * видел предельное значение в обоих местах.
     */
    public static function reportPeriodTooLong(int $maxPeriodDays): self
    {
        $message = (string) __('reporting.errors.PERIOD_TOO_LONG', ['days' => $maxPeriodDays]);

        return new self(ErrorCode::ReportPeriodTooLong, [[
            'field' => 'to',
            'message' => $message,
        ]], null, $message);
    }

    /**
     * В периоде встретилась чужая валюта: отчёт не строится целиком (FR-RPT-154, FR-RPT-193).
     *
     * Идентификаторы заказов и суммы в ответ не попадают.
     */
    public static function reportCurrencyMismatch(): self
    {
        return new self(ErrorCode::ReportCurrencyMismatch);
    }
}
