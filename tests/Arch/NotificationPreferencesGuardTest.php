<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
|--------------------------------------------------------------------------
| Guard-тест экрана настроек уведомлений (ЧТЗ 9.4, пункты 6, 8)
|--------------------------------------------------------------------------
|
| FR-NTF-460, FR-UI-408, FR-OPS-424: наборы типов событий по ролям задаёт
| конфигурация сервиса оповещений. В монолите нет ни дубликата реестра
| событий для экрана, ни перечня «роль → типы событий»: экран строится
| только по ответу сервиса. Проверки читают файлы, база не нужна.
|
*/

const NOTIFICATION_SCREEN_EVENT_TYPE_PATTERN = '/\border\.(?:created|assigned|picked_up|delivered|failed|cancelled)\b/';

/**
 * Файлы PHP и Blade в каталоге.
 *
 * @return list<SplFileInfo>
 */
function notificationScreenFiles(string $path): array
{
    if (! File::isDirectory($path)) {
        return [];
    }

    return array_values(array_filter(
        File::allFiles($path),
        static fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['php'], true),
    ));
}

it('не держит реестр типов событий для экрана настроек', function (): void {
    expect(File::exists(app_path('Domain/Notifications/NotificationEventType.php')))->toBeFalse();
});

it('не задаёт наборы типов событий по ролям в коде монолита', function (): void {
    $violations = [];

    foreach ([app_path('Domain/Notifications'), app_path('Services/Notifications'), app_path('Livewire/Account')] as $path) {
        foreach (notificationScreenFiles($path) as $file) {
            $contents = (string) $file->getContents();

            if (preg_match(NOTIFICATION_SCREEN_EVENT_TYPE_PATTERN, $contents) === 1) {
                $violations[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
    }

    expect($violations)->toBe([]);
});

it('не содержит ключей наборов типов событий и ролей на экране', function (): void {
    $needles = ['sender_event_types', 'courier_event_types', 'dispatcher_event_types', 'admin_event_types'];
    $violations = [];

    foreach (notificationScreenFiles(app_path()) as $file) {
        $contents = (string) $file->getContents();

        foreach ($needles as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = str_replace('\\', '/', $file->getRelativePathname()).':'.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| FR-OPS-424: конфигурация монолита новых ключей не получает
|--------------------------------------------------------------------------
|
| Предпочтения, матрица каналов и Telegram живут только в сервисе
| оповещений. В монолите остаются `admin` и `service` — прокси-клиент.
|
*/

it('не получает блоков Telegram и предпочтений в конфигурации', function (): void {
    /** @var array<string, mixed> $settings */
    $settings = require config_path('notifications.php');

    expect(array_keys($settings))->toBe(['admin', 'service'])
        ->and($settings)->not->toHaveKey('telegram')
        ->and($settings)->not->toHaveKey('preferences');
});

it('не читает переменные окружения предпочтений и Telegram', function (): void {
    $needles = [
        'NOTIFICATIONS_TELEGRAM_',
        'NOTIFICATIONS_PREFERENCES_',
        'NOTIFICATIONS_QUIET_HOURS_',
        'NOTIFICATIONS_CRITICAL_EVENT_TYPES',
        'NOTIFICATIONS_SENDER_EVENT_TYPES',
        'NOTIFICATIONS_COURIER_EVENT_TYPES',
        'NOTIFICATIONS_FALLBACK_ERROR_CODES',
    ];

    $violations = [];

    foreach (array_merge(File::allFiles(app_path()), File::allFiles(config_path())) as $file) {
        $contents = (string) $file->getContents();

        foreach ($needles as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = str_replace('\\', '/', $file->getRelativePathname()).':'.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});
