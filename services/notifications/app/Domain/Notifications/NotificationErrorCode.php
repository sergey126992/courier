<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Внутренние коды причин сервиса (ЧТЗ 8.5).
 *
 * Коды сохраняются в `inbox_messages.error_code`, `notifications.last_error_code`
 * и журнале и не попадают в ответы API. Персональных данных в них нет.
 */
final class NotificationErrorCode
{
    /**
     * Payload или конверт не соответствует общей JSON Schema (FR-NTF-225).
     */
    public const CONTRACT_INVALID = 'EVENT_CONTRACT_INVALID';

    /**
     * Неизвестный тип события или версия контракта (FR-NTF-224).
     */
    public const CONTRACT_UNKNOWN = 'EVENT_CONTRACT_UNKNOWN';

    /**
     * Шаблон пары «событие — канал» не предусмотрен (FR-NTF-247).
     */
    public const TEMPLATE_NOT_FOUND = 'NOTIFICATION_TEMPLATE_NOT_FOUND';

    /**
     * Получатель события не определён (FR-NTF-236).
     */
    public const RECIPIENT_UNKNOWN = 'NOTIFICATION_RECIPIENT_UNKNOWN';

    /**
     * Инфраструктурный сбой отправки: SMTP, журнал фейкового push (FR-NTF-256).
     *
     * Причина повторяется по backoff; в сообщении нет персональных данных.
     */
    public const SEND_FAILED = 'NOTIFICATION_SEND_FAILED';

    /**
     * Программная ошибка отправки: данных журнала недостаточно (ЧТЗ 8.5).
     */
    public const PROGRAM_ERROR = 'NOTIFICATION_PROGRAM_ERROR';

    /**
     * Транспорт событий недоступен (FR-NTF-208).
     */
    public const TRANSPORT_UNAVAILABLE = 'EVENT_TRANSPORT_UNAVAILABLE';

    /**
     * Обработчик не принял событие: сообщение осталось в pending.
     */
    public const CONSUMER_FAILED = 'EVENT_CONSUMER_FAILED';

    /**
     * Сообщение не подтверждено в consumer group.
     */
    public const ACK_FAILED = 'EVENT_ACK_FAILED';

    /**
     * Токен привязки Telegram недействителен: не найден, уже использован
     * или аннулирован новым выпуском (FR-NTF-412, FR-NTF-414).
     */
    public const TELEGRAM_LINK_TOKEN_INVALID = 'TELEGRAM_LINK_TOKEN_INVALID';

    /**
     * Срок действия токена привязки Telegram истёк (FR-NTF-412).
     */
    public const TELEGRAM_LINK_TOKEN_EXPIRED = 'TELEGRAM_LINK_TOKEN_EXPIRED';

    /**
     * Активной привязки Telegram для отвязки нет (FR-NTF-417).
     */
    public const TELEGRAM_BINDING_ABSENT = 'TELEGRAM_BINDING_ABSENT';

    /**
     * Канал Telegram выключен в окружении: токен не выпускается (ЧТЗ 8.3).
     */
    public const TELEGRAM_CHANNEL_DISABLED = 'TELEGRAM_CHANNEL_DISABLED';

    /**
     * Секрет вебхука Telegram не совпал: апдейт отброшен (FR-NTF-442).
     */
    public const TELEGRAM_WEBHOOK_SECRET_INVALID = 'TELEGRAM_WEBHOOK_SECRET_INVALID';

    /**
     * Канал недоступен заранее: активной привязки нет или цепочка каналов
     * пуста (FR-NTF-440, FR-NTF-476).
     *
     * Бизнес-ошибка: строка журнала переходит в `failed` без повтора.
     */
    public const NOTIFICATION_CHANNEL_UNAVAILABLE = 'NOTIFICATION_CHANNEL_UNAVAILABLE';

    /**
     * Telegram не нашёл чат или бот заблокирован пользователем
     * (FR-NTF-437, FR-NTF-439).
     */
    public const TELEGRAM_CHAT_NOT_FOUND = 'TELEGRAM_CHAT_NOT_FOUND';

    /**
     * Лимит запросов Telegram: повтор не раньше `retry_after` (FR-NTF-436).
     */
    public const TELEGRAM_RATE_LIMITED = 'TELEGRAM_RATE_LIMITED';

    /**
     * Инфраструктурный отказ Bot API: сеть, таймаут, 5xx, неверный токен
     * бота (FR-NTF-438).
     */
    public const TELEGRAM_API_FAILED = 'TELEGRAM_API_FAILED';

    /**
     * Прочий детерминированный отказ Bot API (4xx): повтор не назначается,
     * персональных данных в коде нет.
     */
    public const TELEGRAM_REQUEST_REJECTED = 'TELEGRAM_REQUEST_REJECTED';

    /**
     * Детерминированный отказ провайдера канала (FR-NTF-477).
     *
     * Бизнес-ошибка: повтор не помогает, поэтому строка переходит в `failed`,
     * а служба переходит к следующему каналу цепочки замещения.
     */
    public const NOTIFICATION_PROVIDER_REJECTED = 'NOTIFICATION_PROVIDER_REJECTED';

    /**
     * Тип события предпочтений неизвестен сервису (FR-NTF-454).
     */
    public const PREFERENCE_INVALID_EVENT_TYPE = 'PREFERENCE_INVALID_EVENT_TYPE';

    /**
     * Канал предпочтений неизвестен или выключен в окружении (FR-NTF-454).
     */
    public const PREFERENCE_INVALID_CHANNEL = 'PREFERENCE_INVALID_CHANNEL';

    /**
     * Локаль предпочтений не поддерживается сервисом (FR-NTF-454).
     */
    public const PREFERENCE_INVALID_LOCALE = 'PREFERENCE_INVALID_LOCALE';

    /**
     * Окно тихих часов нарушает формат или имеет нулевую длину
     * (FR-NTF-457, FR-NTF-458).
     */
    public const PREFERENCE_INVALID_QUIET_HOURS = 'PREFERENCE_INVALID_QUIET_HOURS';

    /**
     * Критичное событие нельзя оставить без канала email (FR-NTF-455).
     */
    public const PREFERENCE_CRITICAL_CHANNEL_REQUIRED = 'PREFERENCE_CRITICAL_CHANNEL_REQUIRED';

    /**
     * Тип события известен сервису, но недоступен роли получателя
     * (FR-NTF-460).
     */
    public const PREFERENCE_EVENT_TYPE_NOT_ALLOWED = 'PREFERENCE_EVENT_TYPE_NOT_ALLOWED';

    /*
    |--------------------------------------------------------------------------
    | M13 «Демо сервиса»: токены устройств, провайдер push, DLQ, брокер, метрики
    |--------------------------------------------------------------------------
    |
    | Коды ЧТЗ 8.3 (FR-NTF-521…531, FR-OPS-507…512, FR-NTF-541…552). Код
    | хранится в снимке DLQ, журнале попыток и ответе `application/problem+json`;
    | персональных данных и секретов в нём нет.
    |
    */

    /**
     * Токен устройства отозван провайдером (`UNREGISTERED`): повтор по тому
     * же токену не выполняется, применяется замещение каналов M12
     * (FR-NTF-527).
     */
    public const NOTIFICATION_DEVICE_UNREGISTERED = 'NOTIFICATION_DEVICE_UNREGISTERED';

    /**
     * Валидного токена устройства у получателя нет: это не ошибка отправки,
     * запись DLQ не создаётся, применяется цепочка замещения M12 (FR-NTF-528).
     */
    public const NOTIFICATION_PUSH_TOKEN_MISSING = 'NOTIFICATION_PUSH_TOKEN_MISSING';

    /**
     * Провайдер push недоступен: повтор с backoff, при исчерпании — DLQ
     * (FR-NTF-523).
     */
    public const PUSH_PROVIDER_UNAVAILABLE = 'PUSH_PROVIDER_UNAVAILABLE';

    /**
     * Провайдер ограничил частоту: повтор с учётом `Retry-After` в пределах
     * лимита конфигурации (FR-NTF-523).
     */
    public const PUSH_PROVIDER_RATE_LIMITED = 'PUSH_PROVIDER_RATE_LIMITED';

    /**
     * Провайдер отклонил сообщение по бизнес-причине: без повтора, запись
     * DLQ с сохранением кода (FR-NTF-523).
     */
    public const PUSH_PROVIDER_REJECTED = 'PUSH_PROVIDER_REJECTED';

    /**
     * Запись DLQ не найдена по идентификатору (ЧТЗ 8.3, `404`).
     */
    public const DLQ_ENTRY_NOT_FOUND = 'DLQ_ENTRY_NOT_FOUND';

    /**
     * Запись DLQ уже разобрана или уже в повторе: второго эффекта нет
     * (FR-NTF-506, `409`).
     */
    public const DLQ_ENTRY_ALREADY_RESOLVED = 'DLQ_ENTRY_ALREADY_RESOLVED';

    /**
     * Брокер недоступен: `/health` даёт `degraded`, Outbox повторяет
     * публикацию (FR-NTF-542, FR-NTF-548).
     */
    public const BROKER_UNAVAILABLE = 'BROKER_UNAVAILABLE';

    /**
     * Источник метрик недоступен: `/metrics` отдаёт частичные данные,
     * код пишется в журнал (FR-OPS-512).
     */
    public const METRICS_UNAVAILABLE = 'METRICS_UNAVAILABLE';

    /**
     * Поля регистрации токена устройства не прошли проверку (`422`)
     * (FR-NTF-525, FR-NTF-529).
     */
    public const DEVICE_TOKEN_INVALID = 'DEVICE_TOKEN_INVALID';

    /**
     * Токен устройства по идентификатору не найден при отзыве (`404`)
     * (FR-NTF-526).
     */
    public const DEVICE_TOKEN_UNKNOWN = 'DEVICE_TOKEN_UNKNOWN';
}
