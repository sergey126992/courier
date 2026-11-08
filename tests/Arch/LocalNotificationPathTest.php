<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\SplFileInfo;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Локального отправителя уведомлений больше нет (FR-NTF-401…404, FR-NTF-407)
|--------------------------------------------------------------------------
|
| Переходный период M11 удалён: единственный отправитель — сервис
| оповещений. Тест обратный прежнему `LocalNotificationPathTest`: он
| доказывает отсутствие классов, таблиц, команд, регистрации слушателя и
| джобы, чтения `NOTIFICATIONS_DRIVER` и ключей локальной отправки.
|
*/

/**
 * Рабочие PHP-файлы монолита: приложение, маршруты, конфигурация, база.
 *
 * @return list<string>
 */
function guardPhpFiles(): array
{
    $files = [];

    foreach (['app', 'routes', 'config', 'database'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

it('не содержит классов, джобы, слушателя и команд локальной отправки', function (): void {
    $removed = [
        'app/Services/Notifications/DeliveryMode.php',
        'app/Services/Notifications/ChannelResolver.php',
        'app/Services/Notifications/LocalPushProvider.php',
        'app/Services/Notifications/PushProvider.php',
        'app/Services/Notifications/PushProviderFactory.php',
        'app/Services/Notifications/NotificationFailureQueue.php',
        'app/Services/Notifications/AttemptMessageSanitizer.php',
        'app/Services/Notifications/NotificationPolicyFactory.php',
        'app/Services/Notifications/NotificationMessageContext.php',
        'app/Jobs/SendNotification.php',
        'app/Listeners/SendOrderNotifications.php',
        'app/Console/Commands/NotificationsFailed.php',
        'app/Console/Commands/NotificationsRetry.php',
        'app/Console/Commands/PurgeNotifications.php',
        'app/Models/Notifications/Notification.php',
        'app/Models/Notifications/NotificationAttempt.php',
        'app/Models/Notifications/PushMessage.php',
        'app/Mail/NotificationMail.php',
        'app/Domain/Notifications/NotificationPolicy.php',
        'app/Domain/Notifications/NotificationRule.php',
        'app/Domain/Notifications/NotificationTemplate.php',
        'app/Domain/Notifications/NotificationTransitions.php',
        'app/Domain/Notifications/RecipientRole.php',
        'app/Domain/Notifications/NotificationFailureCode.php',
        'resources/views/components/app/delivery-mode.blade.php',
        'resources/views/mail/notifications/order_created_email.blade.php',
    ];

    $existing = array_values(array_filter($removed, static fn (string $path): bool => file_exists(base_path($path))));

    $leftoverFiles = array_merge(
        glob(base_path('app/Models/Notifications/*')) ?: [],
        glob(base_path('app/Domain/Notifications/Exceptions/*')) ?: [],
        glob(database_path('factories/Notifications/*')) ?: [],
        glob(base_path('resources/views/mail/notifications/*')) ?: [],
    );

    expect($existing)->toBe([])
        ->and($leftoverFiles)->toBe([]);
});

it('не оставляет миграций и фабрик локальных таблиц', function (): void {
    $migrations = array_map(
        static fn (SplFileInfo $file): string => $file->getFilename(),
        File::files(database_path('migrations')),
    );

    $local = array_values(array_filter(
        $migrations,
        static fn (string $name): bool => str_contains($name, 'notifications_table')
            || str_contains($name, 'notification_attempts')
            || str_contains($name, 'push_messages')
            || str_contains($name, 'drop_legacy_notification_tables'),
    ));

    expect($local)->toBe([])
        ->and(Schema::hasTable('notifications'))->toBeFalse()
        ->and(Schema::hasTable('notification_attempts'))->toBeFalse()
        ->and(Schema::hasTable('push_messages'))->toBeFalse()
        ->and(Schema::hasTable('order_notification_status'))->toBeTrue();
});

it('не читает NOTIFICATIONS_DRIVER и ключи локальной отправки', function (): void {
    $removedKeys = [
        'notifications.driver',
        'notifications.enabled_channels',
        'notifications.policy',
        'notifications.requires_assignment',
        'notifications.max_attempts',
        'notifications.backoff_seconds',
        'notifications.job_timeout_seconds',
        'notifications.attempt_message_limit',
        'notifications.retention_days',
        'notifications.attempt_retention_days',
        'notifications.push_retention_days',
        'notifications.push_provider',
    ];

    $violations = [];

    foreach (guardPhpFiles() as $path) {
        $contents = (string) file_get_contents($path);

        foreach (array_merge(['NOTIFICATIONS_DRIVER', 'DeliveryMode', 'SendOrderNotifications'], $removedKeys) as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('оставляет в конфигурации уведомлений только service и admin', function (): void {
    /** @var array<string, mixed> $config */
    $config = require config_path('notifications.php');

    expect(array_keys($config))->toEqualCanonicalizing(['admin', 'service'])
        ->and(config('notifications.driver'))->toBeNull()
        ->and(config('notifications.policy'))->toBeNull()
        ->and(config('notifications.enabled_channels'))->toBeNull();
});

it('не объявляет NOTIFICATIONS_DRIVER в окружении и контейнерах', function (): void {
    $files = [base_path('.env.example'), base_path('docker-compose.yml')];

    $violations = [];

    foreach ($files as $path) {
        $contents = (string) file_get_contents($path);

        foreach (['NOTIFICATIONS_DRIVER', 'NOTIFICATIONS_ENABLED_CHANNELS', 'NOTIFICATIONS_PUSH_PROVIDER', 'NOTIFICATIONS_MAX_ATTEMPTS'] as $needle) {
            // У сервиса оповещений свои переменные с теми же именами: проверяется
            // только блок монолита, поэтому строка ищется в первых 120 строках.
            $head = implode("\n", array_slice(explode("\n", $contents), 0, 120));

            if (str_contains($head, $needle)) {
                $violations[] = basename($path).' → '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('не регистрирует слушателя уведомлений и джобу отправки', function (): void {
    $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($provider)->not->toContain('SendOrderNotifications')
        ->and($provider)->not->toContain('DeliveryMode')
        ->and($provider)->not->toContain('notifications.driver_active')
        ->and($provider)->not->toContain('NotificationPolicyFactory')
        ->and($provider)->toContain("config('events.transport')");

    $console = (string) file_get_contents(base_path('routes/console.php'));

    expect($console)->not->toContain('notifications:purge')
        ->and($console)->not->toContain('notifications:retry')
        ->and($console)->not->toContain('notifications:failed');
});

it('читает админ-страницы уведомлений только из сервиса', function (): void {
    $journal = (string) file_get_contents(app_path('Services/Notifications/NotificationAdminJournal.php'));

    expect($journal)->toContain('NotificationServiceClient')
        ->and($journal)->not->toContain('NotificationFailureQueue')
        ->and($journal)->not->toContain('PushMessage')
        ->and($journal)->not->toContain('mode()');
});
