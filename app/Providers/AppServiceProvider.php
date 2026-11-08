<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Dispatch\CourierSelection;
use App\Domain\Dispatch\Strategies\BestEtaCourierStrategy;
use App\Domain\Dispatch\Strategies\LeastLoadedCourierStrategy;
use App\Domain\Dispatch\Strategies\NearestCourierStrategy;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventTypeRegistry;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Contracts\ZoneRepository;
use App\Domain\Geography\ZoneResolver;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\Strategies\CarEstimateStrategy;
use App\Domain\Pricing\Strategies\LightTransportEstimateStrategy;
use App\Domain\Pricing\Strategies\OnFootEstimateStrategy;
use App\Domain\Pricing\StrategyDeliveryEstimator;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Policies\AssignmentPolicy;
use App\Policies\CourierShiftPolicy;
use App\Policies\DeliveryOrderPolicy;
use App\Policies\NotificationAdminPolicy;
use App\Policies\ReportPolicy;
use App\Policies\ServiceZonePolicy;
use App\Services\Events\Amqp\AmqpBroker;
use App\Services\Events\Amqp\PhpAmqpBroker;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\Contracts\EventPublisher;
use App\Services\Events\EventStreamRouter;
use App\Services\Events\JsonSchemaValidator;
use App\Services\Events\LocalEventBus;
use App\Services\Events\OutboxEventEnvelopeFactory;
use App\Services\Events\OutboxPublisher;
use App\Services\Events\RabbitMqEventBus;
use App\Services\Events\RabbitMqTopology;
use App\Services\Events\RedisStreamEventBus;
use App\Services\Geography\CachedDistanceProvider;
use App\Services\Geography\EloquentZoneRepository;
use App\Services\Geography\HaversineDistanceProvider;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Pricing\PricingConfig;
use App\Services\Tracking\PublicTrackingCache;
use App\Support\Docs\ProblemDetailsDocumentTransformer;
use App\Support\Docs\ProblemResponsesTransformer;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Тег набора стратегий расчёта: по нему их получает компонент выбора.
     */
    private const PRICING_STRATEGIES_TAG = 'pricing.estimate-strategies';

    /**
     * Тег набора правил подбора курьера: по нему их получает компонент выбора.
     */
    private const DISPATCH_STRATEGIES_TAG = 'dispatch.assignment-strategies';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EstimateCalculator::class);

        $this->registerApiDocsRoutes();
        $this->registerPricingStrategies();
        $this->registerDispatchStrategies();
        $this->registerGeography();
        $this->registerTracking();
        $this->registerEvents();
        $this->registerNotifications();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureApiDocumentation();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureTrackingCache();
    }

    /**
     * Маршруты документации API существуют только в локальной среде и тестах.
     *
     * Флаг читается при загрузке провайдера пакета, поэтому выставлен в register(),
     * а не в boot(): иначе маршруты уже были бы зарегистрированы. Вне этих
     * окружений адрес /docs/api отвечает 404, даже если SCRAMBLE_ENABLED=true.
     */
    private function registerApiDocsRoutes(): void
    {
        $enabled = (bool) config('scramble.enabled', true);
        $local = $this->app->environment(['local', 'testing']);

        if (! $enabled || ! $local) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    /**
     * Описание API дополняется единым форматом ошибки и ответами, выведенными
     * из middleware маршрута: ручные аннотации в контроллерах не нужны.
     */
    private function configureApiDocumentation(): void
    {
        Scramble::configure()
            ->withDocumentTransformers([ProblemDetailsDocumentTransformer::class])
            ->withOperationTransformers([ProblemResponsesTransformer::class]);
    }

    /**
     * Набор стратегий расчёта регистрируется одним тегом.
     *
     * Вызывающий код получает готовый компонент выбора и не перечисляет классы:
     * новый транспорт — новый класс и одна строка в списке ниже. Параметры
     * транспорта собираются из конфигурации и передаются стратегии при создании.
     */
    private function registerPricingStrategies(): void
    {
        $strategies = $this->pricingStrategies();

        foreach ($strategies as $transport => $strategy) {
            $this->app->bind(
                $strategy,
                static fn (Application $app): DeliveryEstimateStrategy => new $strategy(
                    $app->make(EstimateCalculator::class),
                    $app->make(PricingConfig::class)->transportParams(TransportType::from($transport)),
                ),
            );
        }

        $this->app->tag(array_values($strategies), self::PRICING_STRATEGIES_TAG);

        $this->app->singleton(
            StrategyDeliveryEstimator::class,
            static fn (Application $app): StrategyDeliveryEstimator => new StrategyDeliveryEstimator(
                $app->tagged(self::PRICING_STRATEGIES_TAG),
            ),
        );
    }

    /**
     * Соответствие типа транспорта его стратегии расчёта.
     *
     * @return array<string, class-string<DeliveryEstimateStrategy>>
     */
    private function pricingStrategies(): array
    {
        return [
            TransportType::OnFoot->value => OnFootEstimateStrategy::class,
            TransportType::Light->value => LightTransportEstimateStrategy::class,
            TransportType::Car->value => CarEstimateStrategy::class,
        ];
    }

    /**
     * Набор правил подбора курьера регистрируется одним тегом.
     *
     * Вызывающий код получает компонент выбора и не перечисляет классы: новое
     * правило — новый класс и одна строка в списке ниже (FR-DSP-121, 126).
     * Правила только сравнивают кандидатов, поэтому зависимостей у них нет.
     */
    private function registerDispatchStrategies(): void
    {
        $strategies = [
            NearestCourierStrategy::class,
            LeastLoadedCourierStrategy::class,
            BestEtaCourierStrategy::class,
        ];

        $this->app->tag($strategies, self::DISPATCH_STRATEGIES_TAG);

        $this->app->singleton(
            CourierSelection::class,
            static fn (Application $app): CourierSelection => new CourierSelection(
                $app->tagged(self::DISPATCH_STRATEGIES_TAG),
            ),
        );
    }

    /**
     * География: справочник зон и провайдер расстояний.
     *
     * Домен работает только с контрактами: расстояние считается локальным
     * провайдером через кэширующий декоратор, поэтому замена провайдера —
     * правка одной строки здесь, а не в домене.
     */
    private function registerGeography(): void
    {
        $this->app->bind(ZoneRepository::class, EloquentZoneRepository::class);
        $this->app->bind(ZoneResolver::class);

        $this->app->singleton(DistanceProvider::class, static fn (): DistanceProvider => new CachedDistanceProvider(
            inner: new HaversineDistanceProvider(
                (float) config('geography.distance.route_elongation_factor'),
            ),
            ttlSeconds: (int) config('geography.distance.cache_ttl_seconds'),
            keyDecimals: (int) config('geography.distance.cache_key_decimals'),
            lockTtlSeconds: (int) config('geography.distance.lock.ttl_seconds'),
            lockWaitSeconds: (int) config('geography.distance.lock.wait_seconds'),
        ));
    }

    /**
     * Кэш публичного ответа трекинга.
     *
     * Срок и разброс приходят из конфигурации, а не из кода: значения читаются
     * при первом обращении, поэтому настройка меняется без правки класса.
     */
    private function registerTracking(): void
    {
        $this->app->singleton(PublicTrackingCache::class, static fn (): PublicTrackingCache => new PublicTrackingCache(
            ttlSeconds: (int) config('tracking.public_cache_seconds'),
            jitterRatio: (float) config('tracking.public_cache_jitter_ratio'),
        ));
    }

    /**
     * Публикация событий и шина (FR-NTF-121…126, FR-NTF-201…208).
     *
     * Единственное место выбора транспорта: домен и действия знают только
     * контракт EventBus. Карта слушателей обслуживает режим in-memory и
     * локальный путь M7, а адаптер Redis Streams получает имена потоков,
     * группу, префикс окружения и задержку повторной выдачи из конфигурации.
     */
    private function registerEvents(): void
    {
        $this->app->singleton(EventTypeRegistry::class, static function (): EventTypeRegistry {
            $version = EventSchemaVersion::tryFrom((string) config('events.schema_version'));

            return new EventTypeRegistry($version ?? EventSchemaVersion::V1);
        });

        $this->app->singleton(JsonSchemaValidator::class, static function (): JsonSchemaValidator {
            $path = (string) config('events.schema_path');

            // Относительный путь считается от корня приложения: схемы лежат
            // внутри проекта и читаются тем же классом при любом окружении.
            return new JsonSchemaValidator(str_starts_with($path, '/') ? $path : base_path($path));
        });

        $this->app->singleton(EventStreamRouter::class, static fn (): EventStreamRouter => new EventStreamRouter(
            orders: (string) config('events.streams.orders'),
            notifications: (string) config('events.streams.notifications'),
            contacts: (string) config('events.streams.contacts'),
        ));

        // Шина in-memory: карта слушателей пуста, потому что единственный
        // отправитель уведомлений — сервис (FR-NTF-406). Экземпляр один, чтобы
        // публикатор и тесты транспорта работали с одной шиной.
        $this->app->singleton(LocalEventBus::class, static fn (): LocalEventBus => new LocalEventBus);

        // Брокер событий M13 (FR-NTF-541…552): параметры подключения — из
        // окружения, пароль в журнал не попадает. Экземпляр общий для шины
        // и проверок, поэтому соединение открывается один раз.
        $this->app->singleton(AmqpBroker::class, static fn (): AmqpBroker => new PhpAmqpBroker([
            'host' => (string) config('events.rabbitmq.host'),
            'port' => (int) config('events.rabbitmq.port'),
            'user' => (string) config('events.rabbitmq.user'),
            'password' => (string) config('events.rabbitmq.password'),
            'vhost' => (string) config('events.rabbitmq.vhost'),
            'heartbeat' => (int) config('events.rabbitmq.heartbeat'),
            'timeout' => (int) config('events.rabbitmq.timeout'),
            'prefetch' => max(1, (int) config('events.rabbitmq.prefetch')),
        ]));

        $this->app->singleton(RabbitMqTopology::class, static function (): RabbitMqTopology {
            /** @var array<string, string> $routing */
            $routing = (array) config('events.rabbitmq.routing', []);
            /** @var array<string, string> $streams */
            $streams = (array) config('events.streams', []);

            return new RabbitMqTopology([
                'exchange' => (string) config('events.rabbitmq.exchange', ''),
                'dlx_exchange' => (string) config('events.rabbitmq.dlx_exchange', ''),
                'message_ttl_seconds' => (int) config('events.rabbitmq.message_ttl_seconds', 0),
                'max_attempts' => (int) config('events.rabbitmq.max_attempts', 0),
            ], $routing, array_values($streams));
        });

        $this->app->singleton(EventBus::class, static function (Application $app): EventBus {
            $transport = (string) config('events.transport');

            return match ($transport) {
                'in-memory' => $app->make(LocalEventBus::class),
                'redis' => new RedisStreamEventBus(
                    redis: Redis::connection(),
                    stream: (string) config('events.streams.orders'),
                    prefix: (string) config('events.stream_prefix'),
                    pendingIdleSeconds: (int) config('events.pending_idle_seconds'),
                    // Поток выбирается по типу события: контакты в своём потоке (FR-NTF-231).
                    router: $app->make(EventStreamRouter::class),
                ),
                // Опциональный брокер M13 (FR-NTF-541…552); без профиля broker
                // основной запуск идёт на Redis (FR-NTF-546).
                'rabbitmq' => new RabbitMqEventBus(
                    broker: $app->make(AmqpBroker::class),
                    topology: $app->make(RabbitMqTopology::class),
                    pendingIdleSeconds: (int) config('events.pending_idle_seconds'),
                ),
                default => throw new InvalidArgumentException(sprintf(
                    'Транспорт событий "%s" не поддержан конфигурацией events.transport.',
                    $transport,
                )),
            };
        });

        $this->app->bind(OutboxPublisher::class, static function (Application $app): OutboxPublisher {
            /** @var array<int, mixed> $backoff */
            $backoff = (array) config('events.publish_backoff_seconds');

            // Отправитель событий один — сервис оповещений: событие уходит
            // в шину, выбранную конфигурацией events.transport (FR-NTF-298).
            return new OutboxPublisher(
                bus: $app->make(EventBus::class),
                envelopes: $app->make(OutboxEventEnvelopeFactory::class),
                validator: $app->make(JsonSchemaValidator::class),
                batchSize: (int) config('events.publish_batch_size'),
                maxAttempts: (int) config('events.publish_max_attempts'),
                backoffSeconds: array_values(array_map(
                    static fn (mixed $value): int => (int) $value,
                    $backoff,
                )),
                lockTtlSeconds: (int) config('events.publish_lock_ttl_seconds'),
            );
        });

        $this->app->bind(
            EventPublisher::class,
            static fn (Application $app): EventPublisher => $app->make(OutboxPublisher::class),
        );
    }

    /**
     * Клиент служебного API сервиса оповещений (FR-NTF-405).
     *
     * Единственный источник данных админ-страниц: локального журнала в
     * монолите нет, режимов доставки тоже (FR-NTF-403, FR-NTF-406).
     */
    private function registerNotifications(): void
    {
        // Клиент служебного API сервиса: адрес, токен, таймаут и повторы
        // читаются здесь один раз (ЧТЗ 8.2, FR-OPS-206).
        $this->app->bind(NotificationServiceClient::class, static fn (): NotificationServiceClient => new NotificationServiceClient(
            baseUrl: (string) config('notifications.service.url'),
            token: (string) config('notifications.service.token'),
            timeoutSeconds: max(1, (int) config('notifications.service.timeout_seconds', 5)),
            retryTimes: max(0, (int) config('notifications.service.retry_times', 1)),
            retrySleepMilliseconds: max(0, (int) config('notifications.service.retry_sleep_milliseconds', 0)),
        ));
    }

    /**
     * Смена статуса заказа сбрасывает кэш публичного ответа (FR-TRK-137).
     *
     * Статус меняется только через DeliveryOrder::applyTransition, поэтому
     * события модели достаточно: действия назначения, забора, доставки,
     * неудачи и отмены о кэше отдельно не знают.
     */
    private function configureTrackingCache(): void
    {
        DeliveryOrder::updated(static function (DeliveryOrder $order): void {
            if (! $order->wasChanged('status')) {
                return;
            }

            app(PublicTrackingCache::class)->forget((string) $order->tracking_code);
        });
    }

    /**
     * Права расчёта и справочников.
     */
    private function configureAuthorization(): void
    {
        // Политики регистрируются явно: модели лежат в подпапках Geography, Order
        // и Dispatch, и автоматический поиск политики для них неоднозначен.
        Gate::policy(ServiceZone::class, ServiceZonePolicy::class);
        Gate::policy(DeliveryOrder::class, DeliveryOrderPolicy::class);
        Gate::policy(CourierShift::class, CourierShiftPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);

        Gate::define(
            'estimate-delivery',
            static fn (User $user): bool => $user->role->canCreateOrders(),
        );

        // Отчёт по заказам: правило роли живёт в ReportPolicy, Gate лишь
        // делает его доступным в запросе и в контейнере (FR-RPT-104).
        Gate::define(
            'reports:read',
            static fn (User $user): bool => app(ReportPolicy::class)->view($user),
        );

        // Административные страницы уведомлений: правило роли живёт в политике,
        // Gate делает его доступным маршруту и Livewire (ЧТЗ 8.2, FR-NTF-293).
        Gate::define(
            'notifications:manage',
            static fn (User $user): bool => app(NotificationAdminPolicy::class)->view($user),
        );

        // Просмотр описания API: схема раскрывает все эндпоинты, поэтому вне
        // локальной среды и тестов доступа нет (маршрута там и не существует).
        // Параметр пользователя необязателен: документация открыта без токена.
        Gate::define(
            'viewApiDocs',
            static fn (?User $user = null): bool => app()->environment(['local', 'testing']),
        );
    }

    /**
     * Именованные лимиты запросов доступа.
     *
     * Значения читаются из config/rate_limits.php в момент запроса, поэтому
     * лимит меняется конфигурацией без правки кода.
     */
    private function configureRateLimiting(): void
    {
        // Лимит входа не задаётся здесь: считаются только неудачные попытки,
        // поэтому ограничение живёт в операции входа (LoginAttemptLimiter).

        // Регистрация: ключ IP — массовое создание аккаунтов с одного адреса закрывается.
        RateLimiter::for('auth-register', static fn (Request $request): Limit => self::limit(
            'register',
            (string) $request->ip(),
        ));

        // Повторная отправка письма подтверждения: ключ — пользователь.
        RateLimiter::for('auth-email-resend', static fn (Request $request): Limit => self::limit(
            'email_resend',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Запрос сброса пароля: ключ IP — письма не рассылаются пачками с одного адреса.
        RateLimiter::for('auth-password-forgot', static fn (Request $request): Limit => self::limit(
            'password_forgot',
            (string) $request->ip(),
        ));

        // Предварительный расчёт: ключ — пользователь, чтобы лимит не обходился сменой адреса.
        RateLimiter::for('pricing-estimate', static fn (Request $request): Limit => self::limit(
            'estimate',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Создание заказа: ключ — пользователь, чтобы лимит не обходился сменой адреса.
        RateLimiter::for('orders-create', static fn (Request $request): Limit => self::limit(
            'orders_create',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Публичный трекинг: ключ — IP, обращения не привязаны к пользователю (FR-TRK-136).
        RateLimiter::for('tracking-public', static fn (Request $request): Limit => self::limit(
            'tracking_public',
            (string) $request->ip(),
        ));

        // Создание ссылки трекинга: ключ — пользователь (FR-TRK-131).
        RateLimiter::for('tracking-link', static fn (Request $request): Limit => self::limit(
            'tracking_link',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Приём геоточек: ключ — курьер, чтобы лимит не обходился сменой адреса (FR-TRK-108).
        RateLimiter::for('courier-points', static fn (Request $request): Limit => self::limit(
            'courier_points',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Heartbeat курьера: ключ — курьер (FR-TRK-113).
        RateLimiter::for('courier-heartbeat', static fn (Request $request): Limit => self::limit(
            'courier_heartbeat',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Назначение и переназначение: ключ — диспетчер.
        RateLimiter::for('dispatch-assign', static fn (Request $request): Limit => self::limit(
            'dispatch_assign',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Действия курьера по назначению и смене: ключ — курьер (FR-DSP-149).
        RateLimiter::for('courier-actions', static fn (Request $request): Limit => self::limit(
            'courier_actions',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Отчёт по заказам: ключ — пользователь, тяжёлые агрегаты не запускаются
        // сериями даже со сменой токена (FR-RPT-107, FR-RPT-196).
        RateLimiter::for('reports_read', static fn (Request $request): Limit => self::limit(
            'reports_read',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
    }

    /**
     * Лимит из конфигурации: ключ, сколько запросов и окно в секундах.
     */
    private static function limit(string $name, string $key): Limit
    {
        return new Limit(
            $key,
            (int) config('rate_limits.'.$name.'.attempts'),
            (int) config('rate_limits.'.$name.'.decay_seconds'),
        );
    }
}
