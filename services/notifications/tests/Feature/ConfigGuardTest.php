<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
|--------------------------------------------------------------------------
| Guard каркаса конфигурации M13 (ЧТЗ 9.6, пункты 3, 4, 5)
|--------------------------------------------------------------------------
|
| FR-OPS-508, FR-NTF-549: сроки, лимиты, пороги, имена очередей и границы
| гистограммы живут только в конфигурации и читаются из окружения. В коде
| приложения нет ни `env()`, ни литералов этих значений; неизвестный
| транспорт и провайдер останавливают процесс ошибкой конфигурации
| (FR-NTF-524, FR-NTF-545).
|
*/

/**
 * PHP-файлы каталога рекурсивно.
 *
 * @return list<SplFileInfo>
 */
function configGuardPhpFiles(string $path): array
{
    if (! File::isDirectory($path)) {
        return [];
    }

    return array_values(array_filter(
        File::allFiles($path),
        static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
    ));
}

/**
 * Новые переменные окружения M13: читаются только конфигурацией.
 *
 * @return list<string>
 */
function configGuardM13Variables(): array
{
    return [
        'NOTIFICATIONS_DLQ_RETENTION_DAYS',
        'NOTIFICATIONS_DLQ_PAGE_LIMIT',
        'NOTIFICATIONS_DLQ_MAX_PAGE_LIMIT',
        'NOTIFICATIONS_DEVICE_TOKEN_RETENTION_DAYS',
        'NOTIFICATIONS_METRICS_ENABLED',
        'NOTIFICATIONS_METRICS_SNAPSHOT_TTL_SECONDS',
        'NOTIFICATIONS_METRICS_HISTOGRAM_BUCKETS',
        'FCM_PROJECT_ID',
        'FCM_CREDENTIALS_PATH',
        'FCM_TIMEOUT_SECONDS',
        'FCM_RETRY_TIMES',
        'FCM_RETRY_SLEEP_MS',
        'RABBITMQ_HOST',
        'RABBITMQ_PORT',
        'RABBITMQ_USER',
        'RABBITMQ_PASSWORD',
        'RABBITMQ_VHOST',
        'RABBITMQ_HEARTBEAT',
        'RABBITMQ_PREFETCH',
        'RABBITMQ_MESSAGE_TTL_SECONDS',
        'RABBITMQ_MAX_ATTEMPTS',
        'RABBITMQ_EXCHANGE',
        'RABBITMQ_QUEUE',
        'RABBITMQ_DLX_EXCHANGE',
        'RABBITMQ_DLQ_QUEUE',
    ];
}

it('читает окружение только в конфигурации сервиса', function (): void {
    $violations = [];

    foreach (['app', 'routes', 'database'] as $directory) {
        foreach (configGuardPhpFiles(base_path($directory)) as $file) {
            // `putenv()` — не чтение окружения сервиса; ищется именно вызов
            // хелпера `env(`, который разрешён только конфигурации.
            if (preg_match('/(?<![A-Za-z0-9_])env\(/', (string) $file->getContents()) === 1) {
                $violations[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
    }

    expect($violations)->toBe([]);
});

it('держит все новые переменные M13 только в конфигурации', function (): void {
    $config = (string) File::get(config_path('notifications.php'));
    $missing = array_values(array_filter(
        configGuardM13Variables(),
        static fn (string $variable): bool => ! str_contains($config, $variable),
    ));

    expect($missing)->toBe([]);

    $violations = [];

    foreach (['app', 'routes', 'database'] as $directory) {
        foreach (configGuardPhpFiles(base_path($directory)) as $file) {
            $contents = (string) $file->getContents();

            foreach (configGuardM13Variables() as $variable) {
                if (str_contains($contents, $variable)) {
                    $violations[] = str_replace('\\', '/', $file->getRelativePathname()).':'.$variable;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('не держит литералов имён очередей и границ гистограммы в коде', function (): void {
    // FR-OPS-508, FR-NTF-549: имена exchange/очередей и ряд границ задаются
    // окружением; повтор этих литералов в приложении означает обход конфигурации.
    // Проверяются строковые литералы: путь конфигурации `notifications.dlq.*`
    // (ключ срока хранения) сам по себе именем очереди не является (ЧТЗ 8.5).
    $literals = ["'events.dlx'", '"events.dlx"', "'notifications.events'", '"notifications.events"',
        "'notifications.dlq'", '"notifications.dlq"', "'0.1,0.5,1,2,5,10'", '"0.1,0.5,1,2,5,10"'];

    $violations = [];

    foreach (['app', 'routes', 'database'] as $directory) {
        foreach (configGuardPhpFiles(base_path($directory)) as $file) {
            $contents = (string) $file->getContents();

            foreach ($literals as $literal) {
                if (str_contains($contents, $literal)) {
                    $violations[] = str_replace('\\', '/', $file->getRelativePathname()).':'.$literal;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('не импортирует фреймворк, базу и конфигурацию в домене сервиса', function (): void {
    $files = configGuardPhpFiles(app_path('Domain'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = (string) $file->getContents();

        // Домен знает состояния DLQ и коды причин, но не знает ни базы,
        // ни транспорта, ни конфигурации (FR-NTF-541, ЧТЗ 9.7).
        expect($contents)->not->toContain('Illuminate')
            ->and($contents)->not->toContain('config(')
            ->and($contents)->not->toContain('env(')
            ->and($contents)->not->toContain('app(')
            ->and($contents)->not->toContain('DB::')
            ->and($contents)->not->toContain('Redis')
            ->and($contents)->not->toContain('RabbitMq')
            ->and($contents)->not->toContain('Http::');
    }
});

it('не хранит секретов M13 в примере окружения', function (): void {
    $example = (string) File::get(base_path('.env.example'));

    // Креды FCM приходят только окружением (FR-NTF-522): в примере пусто.
    expect(preg_match('/^FCM_PROJECT_ID=\r?$/m', $example))->toBe(1)
        ->and(preg_match('/^FCM_CREDENTIALS_PATH=\r?$/m', $example))->toBe(1);

    // Реальных адресов брокера и провайдера в репозитории нет (FR-NTF-549).
    // Значения контейнеров проверяет `docker compose config` (точка проверки S1).
    expect($example)->not->toContain('cloudamqp')
        ->and($example)->not->toContain('amazonaws')
        ->and($example)->not->toContain('fcm.googleapis.com')
        ->not->toContain('googleapis.com');
});
