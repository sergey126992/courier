<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Тип доменного события этапа M7 (FR-NTF-111, FR-NTF-115).
 *
 * Имена стабильны: нижний регистр, dot.case, без переименований. Значение
 * совпадает с event_type в outbox_messages: изменение смысла события
 * выполняется новым типом, а не правкой существующего.
 */
enum EventType: string
{
    case OrderCreated = 'order.created';
    case OrderAssigned = 'order.assigned';
    case OrderPickedUp = 'order.picked_up';
    case OrderDelivered = 'order.delivered';
    case OrderFailed = 'order.failed';
    case OrderCancelled = 'order.cancelled';
    case CourierShiftStarted = 'courier.shift.started';
    case CourierShiftEnded = 'courier.shift.ended';
    case UserContactChanged = 'user.contact_changed';
    /**
     * Обратные события сервиса оповещений о результате доставки (FR-NTF-281).
     *
     * Монолит их только потребляет: проекция `order_notification_status`
     * показывает статус в карточке заказа (FR-NTF-284).
     */
    case NotificationSent = 'notification.sent';
    case NotificationFailed = 'notification.failed';

    /**
     * Агрегат, которому принадлежит событие.
     */
    public function aggregateType(): AggregateType
    {
        return match ($this) {
            self::CourierShiftStarted, self::CourierShiftEnded => AggregateType::CourierShift,
            self::UserContactChanged => AggregateType::User,
            self::NotificationSent, self::NotificationFailed => AggregateType::Notification,
            default => AggregateType::Order,
        };
    }

    /**
     * Обязательные поля payload по таблице 8.3 ЧТЗ.
     *
     * Событие заказа несёт общий состав для адресации и рендера в сервисе
     * оповещений (FR-NTF-223): `event_id` (равен message_id строки Outbox),
     * `order_id`, `tracking_code`, `sender_id`, `status`, `occurred_at`,
     * `locale`, а также поля конкретного перехода. Персональных данных нет.
     *
     * Лишние поля допускаются: отсутствие обязательного — ошибка контракта.
     *
     * @return list<string>
     */
    public function requiredPayloadFields(): array
    {
        return match ($this) {
            self::OrderCreated => [
                ...self::orderEventFields(),
                'pickup_district',
                'transport',
                'price_amount',
                'price_currency',
            ],
            self::OrderAssigned => [
                ...self::orderEventFields(),
                'assignment_id',
                'courier_id',
                'assigned_at',
            ],
            self::OrderPickedUp => [
                ...self::orderEventFields(),
                'assignment_id',
                'courier_id',
                'picked_up_at',
            ],
            self::OrderDelivered => [
                ...self::orderEventFields(),
                'assignment_id',
                'courier_id',
                'delivered_at',
            ],
            self::OrderFailed => [
                ...self::orderEventFields(),
                'assignment_id',
                'courier_id',
                'failure_reason',
                'failed_at',
            ],
            // Политика отправки по отмене остаётся прежней: событие получает
            // только общий состав, дополнительных полей перехода у него нет.
            self::OrderCancelled => [
                ...self::orderEventFields(),
                'cancelled_by',
                'cancelled_by_role',
                'cancelled_at',
                'reason_code',
            ],
            self::CourierShiftStarted => [
                'shift_id',
                'courier_id',
                'zone_id',
                'started_at',
            ],
            self::CourierShiftEnded => [
                'shift_id',
                'courier_id',
                'ended_at',
            ],
            self::UserContactChanged => [
                'event_id',
                'user_id',
                'email',
                'locale',
                'occurred_at',
            ],
            // Обратные события сервиса (ЧТЗ 8.3): персональных данных нет.
            self::NotificationSent => self::notificationEventFields(),
            self::NotificationFailed => [
                ...self::notificationEventFields(),
                'error_code',
            ],
        };
    }

    /**
     * Состав payload обратного события о доставке (ЧТЗ 8.3).
     *
     * @return list<string>
     */
    private static function notificationEventFields(): array
    {
        return [
            'event_id',
            'notification_id',
            'order_id',
            'recipient_id',
            'channel',
            'status',
            'attempt',
            'occurred_at',
            'correlation_id',
        ];
    }

    /**
     * Общий состав payload события заказа (FR-NTF-223, ЧТЗ 8.3).
     *
     * `event_id` равен message_id строки Outbox: повторная публикация
     * сохраняет идентификатор, поэтому дедупликация работает и при повторе.
     *
     * @return list<string>
     */
    private static function orderEventFields(): array
    {
        return [
            'event_id',
            'order_id',
            'tracking_code',
            'sender_id',
            'status',
            'occurred_at',
            'locale',
        ];
    }
}
