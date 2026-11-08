<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\Exceptions\NotificationTemplateNotFound;
use App\Domain\Notifications\NotificationTemplate;
use Illuminate\Support\Facades\Lang;

/**
 * Тексты уведомлений сервиса (FR-NTF-247, FR-NTF-258).
 *
 * Шаблон выбирается парой «событие — канал», локаль берётся из события с
 * фолбэком на язык текстов сервиса. Текстов, которых нет в lang, не бывает
 * молча: отсутствие ключа — бизнес-ошибка без автоматического повтора.
 * В текстах только номер заказа, статус и ссылка трекинга: имени, адреса,
 * телефона и email получателя нет (FR-NTF-251).
 */
final class NotificationTexts
{
    /**
     * Строки шаблона, из которых собираются письмо и push.
     *
     * @var list<string>
     */
    private const LINES = ['subject', 'greeting', 'body', 'tracking', 'signature'];

    /**
     * Локаль текста для события: поддерживаемая локаль события или фолбэк.
     */
    public function locale(?string $eventLocale): string
    {
        $fallback = trim((string) config('notifications.locales.fallback'));

        if ($fallback === '') {
            $fallback = 'ru';
        }

        $candidate = strtolower(trim((string) $eventLocale));

        return in_array($candidate, $this->supported(), true) ? $candidate : $fallback;
    }

    /**
     * Тема и тело уведомления с подстановками.
     *
     * @param array<string, string|int> $replacements значения подстановок
     * @return array{subject: string, body: string}
     *
     * @throws NotificationTemplateNotFound текстов шаблона нет в языке сервиса
     */
    public function text(NotificationTemplate $template, ?string $eventLocale, array $replacements = []): array
    {
        $locale = $this->locale($eventLocale);
        $key = $template->langKey();

        if (! Lang::has($key, $locale, false)) {
            throw NotificationTemplateNotFound::make($template->eventType(), $template->channel());
        }

        return [
            'subject' => (string) __($key.'.subject', $replacements, $locale),
            'body' => (string) __($key.'.body', $replacements, $locale),
        ];
    }

    /**
     * Строки шаблона для канала: только те, что заданы в языке сервиса.
     *
     * Используется слоем отправки: письмо берёт тему, приветствие, тело,
     * строку трекинга и подпись, push — тело и строку трекинга. Литеральных
     * текстов в коде отправки нет: всё приходит из lang (FR-NTF-258).
     *
     * @param array<string, string|int> $replacements значения подстановок
     * @return array<string, string> строки шаблона по именам LINES
     *
     * @throws NotificationTemplateNotFound текстов шаблона нет в языке сервиса
     */
    public function lines(NotificationTemplate $template, ?string $eventLocale, array $replacements = []): array
    {
        $locale = $this->locale($eventLocale);
        $key = $template->langKey();

        if (! Lang::has($key, $locale, false)) {
            throw NotificationTemplateNotFound::make($template->eventType(), $template->channel());
        }

        $lines = [];

        foreach (self::LINES as $line) {
            if (Lang::has($key.'.'.$line, $locale, false)) {
                $lines[$line] = (string) __($key.'.'.$line, $replacements, $locale);
            }
        }

        return $lines;
    }

    /**
     * Ссылка трекинга из конфигурации сервиса (FR-NTF-251).
     *
     * Код трекинга подставляется в шаблон адреса; других данных в ссылке нет.
     */
    public function trackingUrl(?string $trackingCode): string
    {
        $url = (string) config('notifications.tracking.url', '');

        return str_replace('{tracking_code}', rawurlencode(trim((string) $trackingCode)), $url);
    }

    /**
     * Поддерживаемые языки текстов из конфигурации.
     *
     * @return list<string>
     */
    private function supported(): array
    {
        /** @var list<mixed> $values */
        $values = (array) config('notifications.locales.supported', []);

        $locales = [];

        foreach ($values as $value) {
            $locale = strtolower(trim((string) $value));

            if ($locale !== '') {
                $locales[] = $locale;
            }
        }

        return $locales;
    }
}
