<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeInterface;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Структурированный JSON-журнал сервиса (FR-OPS-202).
 *
 * В stderr пишется одна JSON-запись на строку. В контексте остаются только
 * технические поля из белого списка: адрес, телефон, токен, тело письма и
 * содержимое push не могут попасть в журнал, даже если их передали
 * в контекст по ошибке. Текст сообщения — постоянная строка кода, поэтому
 * персональные данные в него не подставляются.
 *
 * Числовое поле `count` — счётчик операционного журнала (FR-OPS-208):
 * отправленные, необработанные и повторённые уведомления по каналам.
 * Событийные счётчики M12 пишутся той же формой: `channel`, `status`,
 * `count` (FR-OPS-413).
 */
final class TechnicalLogFormatter implements FormatterInterface
{
    /**
     * Разрешённые поля контекста журнала (FR-OPS-202, FR-OPS-208).
     *
     * @var list<string>
     */
    /**
     * Шаблон адреса электронной почты для вычищения текста сообщения.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    /**
     * Шаблоны телефонов для вычищения текста сообщения.
     *
     * @var list<string>
     */
    private const PHONE_PATTERNS = [
        '/\+\d[\d\s().-]{8,}\d/',
        '/\b8\d{10}\b/',
    ];

    /**
     * Разрешённые поля контекста журнала (FR-OPS-202, FR-OPS-208).
     *
     * @var list<string>
     */
    public const ALLOWED_FIELDS = [
        'correlation_id',
        'event_id',
        'notification_id',
        'order_id',
        'recipient_id',
        // Пользователь в записях M12 обозначается `user_id` (FR-OPS-411);
        // это идентификатор, а не адрес, токен или `chat_id`.
        'user_id',
        'channel',
        'status',
        'attempt',
        'event_type',
        'error_code',
        // Отпечаток device-токена и платформа: сам токен в журнал не попадает
        // (FR-NTF-529, FR-NTF-531).
        'fingerprint',
        'platform',
        // Пауза повтора из ответа Telegram `429` и канал, который заменён
        // следующим (FR-OPS-411, FR-OPS-413).
        'retry_after',
        'fallback_from',
        'count',
        // Лаг потребления и число необработанных доступны и в журнале,
        // значения читаются без разбора текста (FR-OPS-203).
        'lag_seconds',
        'pending',
    ];

    /**
     * Оставляет в контексте только технические поля со скалярными значениями.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string|int|float|bool>
     */
    public static function filter(array $fields): array
    {
        $filtered = [];

        foreach (self::ALLOWED_FIELDS as $name) {
            $value = $fields[$name] ?? null;

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $filtered[$name] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param LogRecord $record запись Monolog
     */
    public function format(LogRecord $record): string
    {
        return $this->encode([
            'timestamp' => $record->datetime->format(DateTimeInterface::ATOM),
            'level' => strtolower($record->level->getName()),
            // Текст сообщения вычищается от адреса и телефона: строка
            // исключения провайдера не должна нести персональные данные.
            'message' => self::redact($record->message),
            'context' => self::filter($record->context),
        ]);
    }

    /**
     * @param array<LogRecord> $records записи одного пакета
     */
    public function formatBatch(array $records): string
    {
        $batch = '';

        foreach ($records as $record) {
            $batch .= $this->format($record);
        }

        return $batch;
    }

    /**
     * Заменяет адрес и телефон в тексте сообщения (FR-OPS-202).
     */
    public static function redact(string $message): string
    {
        $message = preg_replace(self::EMAIL_PATTERN, '[redacted-email]', $message) ?? $message;

        foreach (self::PHONE_PATTERNS as $pattern) {
            $message = preg_replace($pattern, '[redacted-phone]', $message) ?? $message;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
