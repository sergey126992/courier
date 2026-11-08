<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notifications\NotificationPolicy;
use App\Logging\TechnicalLogContext;
use App\Logging\TechnicalLogger;
use App\Metrics\MetricsSnapshotBuilder;
use App\Metrics\MetricsStore;
use App\Metrics\NotificationMetrics;
use App\Metrics\PrometheusTextExporter;
use App\Metrics\StreamLagProbe;
use App\Services\Events\Amqp\AmqpBroker;
use App\Services\Events\Amqp\PhpAmqpBroker;
use App\Services\Events\BrokerHealth;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\EventContractValidator;
use App\Services\Events\RabbitMqEventStream;
use App\Services\Events\RabbitMqTopology;
use App\Services\Events\RedisEventStream;
use App\Services\Events\TransportDriver;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\PushProvider;
use App\Services\Notifications\Channels\PushProviderFactory;
use App\Services\Notifications\Channels\TelegramBotClientFactory;
use App\Services\Notifications\DeviceTokens\DeviceTokenStore;
use App\Services\Notifications\DeviceTokens\EloquentDeviceTokenStore;
use App\Services\Notifications\NotificationCounters;
use App\Services\Notifications\NotificationPolicyFactory;
use App\Services\Notifications\OutboxPublisher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Технический контекст журнала один на процесс: сквозной correlation_id
        // действует в области обработки записи (FR-OPS-201, FR-OPS-202).
        $this->app->singleton(TechnicalLogContext::class);

        // Счётчики событий M12 живут в памяти процесса и один на приложение:
        // инкремент в момент события и агрегат для чтения — один объект
        // (FR-OPS-413, FR-OPS-418). Счётчики и гистограмма отправки M13
        // сбрасываются в общий кэш, поэтому объект один на процесс отправки
        // (FR-OPS-502, FR-OPS-508).
        $this->app->singleton(NotificationCounters::class, static fn (Application $app): NotificationCounters => new NotificationCounters(
            store: $app->make(MetricsStore::class),
            logger: $app->make(TechnicalLogger::class),
            histogramBuckets: array_values(array_map(
                static fn (mixed $bound): float => (float) $bound,
                (array) config('notifications.metrics.histogram_buckets', []),
            )),
        ));

        // Снимок метрик M13 (FR-OPS-501…508): значения счётчиков, гистограммы
        // и «дорогих» gauge общие для процессов отправки, расписания и HTTP,
        // поэтому документы живут в кэше, а не в памяти процесса.
        $this->app->bind(MetricsStore::class, static fn (): MetricsStore => new MetricsStore(
            store: (string) config('notifications.metrics.cache_store', ''),
            prefix: (string) config('notifications.metrics.cache_prefix', ''),
            ttlSeconds: max(1, (int) config('notifications.metrics.snapshot_ttl_seconds', 30)),
        ));

        // Белый список меток (FR-OPS-507): экспортёр отбрасывает всё, что
        // не входит в набор, поэтому идентификаторы в ответ не попадают.
        $this->app->bind(PrometheusTextExporter::class, static fn (): PrometheusTextExporter => new PrometheusTextExporter(
            array_values(array_map(
                static fn (mixed $label): string => (string) $label,
                (array) config('notifications.metrics.labels', []),
            )),
        ));

        $this->app->bind(NotificationMetrics::class, fn (Application $app): NotificationMetrics => new NotificationMetrics(
            $app->make(MetricsStore::class),
            $app->make(TechnicalLogger::class),
            $this->streams(),
            (string) config('notifications.transport.stream_prefix', ''),
        ));

        $this->app->bind(StreamLagProbe::class, static fn (): StreamLagProbe => new StreamLagProbe(
            (string) config('notifications.transport.consumer_group', ''),
            max(1, (int) config('notifications.transport.batch_size', 1)),
        ));

        $this->app->bind(MetricsSnapshotBuilder::class, fn (Application $app): MetricsSnapshotBuilder => new MetricsSnapshotBuilder(
            $app->make(MetricsStore::class),
            $app->make(StreamLagProbe::class),
            $app->make(TechnicalLogger::class),
            $this->streams(),
            (string) config('notifications.transport.stream_prefix', ''),
        ));

        // Таймаут подключения к базе (FR-OPS-205): libpq читает его из
        // окружения процесса при соединении, значение приходит из конфигурации.
        $connectTimeout = (int) config('database.connections.pgsql.connect_timeout', 0);

        if ($connectTimeout > 0) {
            putenv('PGCONNECT_TIMEOUT='.$connectTimeout);
        }

        // Транспорт событий: Redis Streams по умолчанию и RabbitMQ как опция
        // (FR-NTF-202, FR-NTF-541…552). Потребитель зависит только от
        // контракта EventStream. Неизвестное значение останавливает процесс
        // ошибкой конфигурации, а не тихим переходом в Redis (FR-NTF-545).
        $this->app->singleton(AmqpBroker::class, fn (): AmqpBroker => new PhpAmqpBroker($this->brokerConfiguration()));

        $this->app->singleton(RabbitMqTopology::class, fn (): RabbitMqTopology => new RabbitMqTopology(
            $this->topologyConfiguration(),
            $this->streams(),
            $this->routing(),
        ));

        $this->app->singleton(BrokerHealth::class, function (Application $app): BrokerHealth {
            $driver = TransportDriver::resolve((string) config('notifications.transport.driver'));

            if ($driver !== TransportDriver::RABBITMQ) {
                return BrokerHealth::disabled($driver);
            }

            /** @var RabbitMqTopology $topology */
            $topology = $app->make(RabbitMqTopology::class);

            return BrokerHealth::forBroker(
                $app->make(AmqpBroker::class),
                $topology,
                $driver,
                $this->brokerQueues($topology),
            );
        });

        $this->app->bind(EventStream::class, function (Application $app): EventStream {
            $driver = TransportDriver::resolve((string) config('notifications.transport.driver'));

            return match ($driver) {
                TransportDriver::REDIS => new RedisEventStream(
                    (string) config('notifications.transport.stream_prefix', ''),
                ),
                TransportDriver::RABBITMQ => new RabbitMqEventStream(
                    broker: $app->make(AmqpBroker::class),
                    topology: $app->make(RabbitMqTopology::class),
                    logger: $app->make(TechnicalLogger::class),
                    pendingIdleSeconds: (int) config('notifications.transport.pending_idle_seconds', 60),
                ),
                default => throw new InvalidArgumentException(sprintf(
                    'Транспорт событий «%s» не поддержан конфигурацией notifications.transport.driver.',
                    $driver,
                )),
            };
        });

        // Политика каналов собирается из конфигурации сервиса один раз
        // и дальше используется доменом как готовый объект (FR-NTF-241).
        $this->app->singleton(NotificationPolicy::class, static fn (): NotificationPolicy => (new NotificationPolicyFactory)->make());

        // Хранилище device-токенов поверх таблицы сервиса (FR-NTF-527, FR-NTF-528):
        // провайдер push зависит от интерфейса, а не от Eloquent.
        $this->app->bind(DeviceTokenStore::class, EloquentDeviceTokenStore::class);

        // Канал push зависит только от контракта PushProvider (FR-NTF-253):
        // по умолчанию фабрика отдаёт журнал-симулятор, `fcm` включается
        // конфигурацией без изменения вызывающего кода (FR-NTF-524, FR-NTF-532).
        $this->app->bind(PushProvider::class, static fn (): PushProvider => (new PushProviderFactory)->make());

        // Клиент Bot API Telegram выбирается по конфигурации: в рабочем
        // окружении HTTP-адаптер, в тестах и демонстрации — фейк. Токен бота
        // и параметры транспорта приходят только из окружения, литеральных
        // значений нет (FR-NTF-441, FR-NTF-443). Обработчик апдейтов и канал
        // зависят от контракта.
        $this->app->bind(TelegramBotClient::class, static fn (): TelegramBotClient => (new TelegramBotClientFactory)->make());

        // Публикатор Outbox сервиса: поток, пачка, попытки и задержки — из
        // конфигурации, литералов в команде нет (FR-NTF-283).
        $this->app->bind(OutboxPublisher::class, static function (Application $app): OutboxPublisher {
            /** @var array<int, mixed> $backoff */
            $backoff = (array) config('notifications.outbox.publish_backoff_seconds', []);

            return new OutboxPublisher(
                stream: $app->make(EventStream::class),
                validator: $app->make(EventContractValidator::class),
                batchSize: (int) config('notifications.outbox.publish_batch_size', 100),
                maxAttempts: (int) config('notifications.outbox.publish_max_attempts', 5),
                backoffSeconds: array_values(array_map(
                    static fn (mixed $value): int => (int) $value,
                    $backoff,
                )),
                lockTtlSeconds: (int) config('notifications.outbox.publish_lock_ttl_seconds', 60),
                streamName: (string) config('notifications.transport.streams.notifications'),
                logger: $app->make(TechnicalLogger::class),
            );
        });
    }

    /**
     * Параметры подключения к брокеру из окружения (FR-NTF-549).
     *
     * @return array{host: string, port: int, user: string, password: string, vhost: string,
     *               heartbeat: int, timeout: int, prefetch: int}
     */
    private function brokerConfiguration(): array
    {
        return [
            'host' => (string) config('notifications.transport.rabbitmq.host', ''),
            'port' => (int) config('notifications.transport.rabbitmq.port', 0),
            'user' => (string) config('notifications.transport.rabbitmq.user', ''),
            'password' => (string) config('notifications.transport.rabbitmq.password', ''),
            'vhost' => (string) config('notifications.transport.rabbitmq.vhost', '/'),
            'heartbeat' => (int) config('notifications.transport.rabbitmq.heartbeat', 0),
            'timeout' => (int) config('notifications.transport.rabbitmq.timeout', 5),
            'prefetch' => max(1, (int) config('notifications.transport.rabbitmq.prefetch', 1)),
        ];
    }

    /**
     * Топология брокера из конфигурации (FR-NTF-542…545).
     *
     * @return array{exchange: string, dlx_exchange: string, dlq_queue: string,
     *               message_ttl_seconds: int, max_attempts: int}
     */
    private function topologyConfiguration(): array
    {
        return [
            'exchange' => (string) config('notifications.transport.rabbitmq.exchange', ''),
            'dlx_exchange' => (string) config('notifications.transport.rabbitmq.dlx_exchange', ''),
            'dlq_queue' => (string) config('notifications.transport.rabbitmq.dlq_queue', ''),
            'message_ttl_seconds' => (int) config('notifications.transport.rabbitmq.message_ttl_seconds', 0),
            'max_attempts' => (int) config('notifications.transport.rabbitmq.max_attempts', 0),
        ];
    }

    /**
     * Домены маршрутизации брокера по префиксу типа события (FR-NTF-542).
     *
     * @return array<string, string>
     */
    private function routing(): array
    {
        $routing = [];

        foreach ((array) config('notifications.transport.rabbitmq.routing', []) as $prefix => $domain) {
            $routing[(string) $prefix] = (string) $domain;
        }

        return $routing;
    }

    /**
     * Очереди потребителей для проверки состояния брокера (FR-NTF-552).
     *
     * @return list<string>
     */
    private function brokerQueues(RabbitMqTopology $topology): array
    {
        $group = (string) config('notifications.transport.consumer_group', '');
        $queues = [];

        foreach ($this->streams() as $stream) {
            if ($stream === '' || $group === '') {
                continue;
            }

            $queues[] = $topology->queueName($stream, $group);
        }

        return $queues;
    }

    /**
     * Потоки событий конфигурации без префикса окружения (FR-OPS-505).
     *
     * @return list<string>
     */
    private function streams(): array
    {
        return array_values(array_map(
            static fn (mixed $stream): string => (string) $stream,
            (array) config('notifications.transport.streams', []),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
