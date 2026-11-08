<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Безопасное сообщение об ошибке для журнала попыток (FR-NTF-273, FR-OPS-202).
 *
 * Причина сохраняется целиком, но без персональных данных: email-подобные
 * и телефоноподобные фрагменты заменяются метками, переводы строк
 * схлопываются, длина ограничивается значением
 * `notifications.sender.attempt_message_limit`.
 */
final class AttemptMessageSanitizer
{
    /**
     * Email-подобный фрагмент: адрес целиком заменяется меткой.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /**
     * Кандидат в телефон: цифры, пробелы и разделители группы.
     */
    private const PHONE_PATTERN = '/\+?\d[\d\s().\-]{7,}\d/u';

    private const EMAIL_PLACEHOLDER = '[email]';

    private const PHONE_PLACEHOLDER = '[телефон]';

    /**
     * Минимум цифр, после которого фрагмент считается телефоном.
     */
    private const PHONE_MIN_DIGITS = 10;

    public function __construct(private readonly ?int $limit = null) {}

    /**
     * Очищает сообщение исключения и усекает его до предела из конфигурации.
     */
    public function sanitize(?string $message): string
    {
        $text = trim((string) $message);

        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace(self::EMAIL_PATTERN, self::EMAIL_PLACEHOLDER, $text);
        $text = (string) preg_replace_callback(
            self::PHONE_PATTERN,
            static function (array $matches): string {
                $digits = (int) preg_match_all('/\d/', $matches[0]);

                return $digits >= self::PHONE_MIN_DIGITS ? self::PHONE_PLACEHOLDER : $matches[0];
            },
            $text,
        );

        return mb_substr($text, 0, $this->limit());
    }

    private function limit(): int
    {
        if ($this->limit !== null) {
            return max(32, $this->limit);
        }

        return max(32, (int) config('notifications.sender.attempt_message_limit', 200));
    }
}
