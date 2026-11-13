# Сервис оповещений

Отдельное приложение Laravel 13 (`courier/services/notifications/`) для отправки
email и push по событиям заказов. Границы сервиса и требования — в
[ЧТЗ - Сервис оповещений](../../.github/docs/Техническое%20задание/Сервис%20оповещений/ЧТЗ%20-%20Сервис%20оповещений.md),
план этапа — в `.github/plans/Сервис оповещений/План - Сервис оповещений (M11).md`.

Состояние: **этапы M11, M12 и M13 реализованы**: приём событий, контакты получателей, отправка с повторами, обратные события, служебный API, `/health` и наблюдаемость (M11); предпочтения получателя, канал Telegram с ботом и привязкой, цепочка замещения каналов, тихие часы и счётчики этапа (M12); очередь необработанных `dlq_entries`, метрики `/metrics`, реальный push-провайдер FCM с токенами устройств и опциональный брокер RabbitMQ (M13).
Приложение собирается, запускается отдельными контейнерами, читает
конфигурацию, держит собственную схему уведомлений и отвечает на `/health`.
Потребитель читает потоки заказов и контактов, проверяет конверты по общим
JSON Schema, создаёт строки журнала по матрице каналов и хранит контакты
получателей. Слой отправки собирает письмо или push из lang-шаблонов и
отправляет по каналу строки журнала. Повторы выполняет поллер отправки,
обратные события публикует собственный Outbox, журнал и ручной повтор
доступны по служебному API.

## Стек

| Слой | Выбор |
|---|---|
| Runtime | PHP 8.5, `php:8.5-fpm-alpine`, multi-stage `Dockerfile` (`php-base` → `vendor` / `dev` → `runtime`) |
| Каркас | Laravel 13, свой `composer.json` и `composer.lock` |
| БД | PostgreSQL 18, отдельная база `notifications` и роль `notifications` |
| Транспорт | Redis 7 Streams через `predis/predis` по умолчанию; RabbitMQ через `php-amqplib/php-amqplib` v3 как опция |
| Расширения PHP | `pdo_pgsql`, `pcntl`, `sockets`, `opcache`; `sockets` обязателен для `php-amqplib` v3 |
| Контракты | JSON Schema draft 2020-12, `opis/json-schema` ^2 |
| Тесты и качество | Pest 4, PHPUnit 12, Pint, Larastan (PHPStan level 8), Paratest 7 |

Sanctum, Livewire и Blade-UI в сервисе не используются: интерфейс живёт
в монолите и обращается к API сервиса. Laravel Queue для отправки не
используется — повтор выполняет поллер по `available_at` (пункт 16).

## Структура

```
app/
  Console/Commands/     команды процессов (notifications:consume, notifications:send, notifications:publish-outbox,
                        notifications:telegram:poll, notifications:telegram:webhook, notifications:telegram:purge;
                        M13: notifications:dlq-list, notifications:dlq-replay, notifications:dlq-purge,
                        notifications:metrics-snapshot, notifications:broker-health; проверка: notifications:check)
  Domain/Events/        типы событий и конверт события
  Domain/Notifications/ политика каналов, роли, каналы, шаблоны, статусы, предпочтения (PreferenceRole, PreferenceValidator,
                        QuietHours, FallbackChain), исключения
  Services/Events/      потребители потоков, транспорт Redis Streams, конверт, проверка схем, Inbox
  Services/Notifications/ политика из конфигурации, получатели, эффект события, тексты,
                        контакты получателей и слой отправки
  Services/Notifications/Channels/ email, push и telegram-каналы, контракты PushProvider и TelegramBotClient,
                        HttpTelegramBotClient, FakeTelegramBotClient, TelegramBotClientFactory
  Mail/                 письмо уведомления сервиса (Mailable)
  Http/Controllers/     базовый контроллер, HealthController (/health), MetricsController (/metrics), Api/* (журнал, DLQ, device-токены, предпочтения, привязки)
  Models/               модели таблиц сервиса и трейт приведения моментов к UTC
  Support/              каталог схем контрактов и проверка состояния сервиса
bootstrap/              запуск приложения (web, api /api/v1, console, /health, /up)
config/notifications.php  транспорт, контракты, доступ, процессы, хранение, пороги /health
contracts/ (монтируется)  общие JSON Schema из courier/contracts/notifications
database/migrations/    таблицы сервиса (M11: восемь, M12 добавляет notification_preferences и telegram_link_tokens,
                        M13 — dlq_entries и device_tokens), код трекинга
database/factories/     фабрики моделей для тестов
docker/php/             php.ini, пул php-fpm, entrypoint (с самопроверкой), run-tests.sh
lang/{locale}/notifications.php  тексты уведомлений и писем (пункты 13, 15)
resources/views/mail/   Blade-шаблон письма сервиса
routes/                 web (служебный корень), api, console
tests/Unit, tests/Feature  проверки каркаса, конфигурации и guard-тесты схемы
```

## Переменные окружения

Все настройки читаются только из окружения (FR-OPS-211…213), значения по
умолчанию безопасны для разработки, реальных адресов и секретов в репозитории
нет (FR-OPS-212). Полный список — в `.env.example` и в сервисах
`notifications-*` файла `courier/docker-compose.yml`.

Ключевые переменные:

- `DB_*` — подключение к собственной базе `notifications` (роль `notifications`);
- `REDIS_*` — Redis Streams (`REDIS_CLIENT=predis`);
- `NOTIFICATIONS_SCHEMA_PATH` — каталог общих схем, монтируется только для чтения;
- `NOTIFICATIONS_API_TOKEN` — служебный токен API (значение по умолчанию только для разработки);
- `NOTIFICATIONS_STREAM_*`, `NOTIFICATIONS_CONSUMER_GROUP`, `NOTIFICATIONS_CONSUMER_NAME`,
  `NOTIFICATIONS_CONSUME_BATCH_SIZE`, `NOTIFICATIONS_PENDING_IDLE_SECONDS` — транспорт и потребление;
- `NOTIFICATIONS_ENABLED_CHANNELS` — включённые каналы доставки (выключенный канал
  отбрасывается без уведомления и без ошибки, FR-NTF-245);
- `NOTIFICATIONS_SUPPORTED_LOCALES`, `NOTIFICATIONS_LOCALE_FALLBACK`,
  `NOTIFICATIONS_TRACKING_URL` — языки текстов и ссылка трекинга;
- `NOTIFICATIONS_MAX_ATTEMPTS`, `NOTIFICATIONS_BACKOFF_SECONDS`,
  `NOTIFICATIONS_SENDER_POLL_INTERVAL_SECONDS` — повторы и поллер отправки;
- `NOTIFICATIONS_SEND_TIMEOUT_SECONDS`, `MAIL_TIMEOUT`, `DB_CONNECT_TIMEOUT`,
  `REDIS_CONNECT_TIMEOUT`, `REDIS_READ_TIMEOUT`, `NOTIFICATIONS_SENDER_RETRY_AFTER_SECONDS` — таймауты
  и пауза повтора отправки (FR-OPS-205); пауза строго больше времени обработки пачки;
- `NOTIFICATIONS_RETENTION_DAYS`, `NOTIFICATIONS_ATTEMPT_RETENTION_DAYS`, `NOTIFICATIONS_PUSH_RETENTION_DAYS`,
  `NOTIFICATIONS_INBOX_RETENTION_DAYS`, `NOTIFICATIONS_DEDUP_RETENTION_DAYS`, `NOTIFICATIONS_PURGE_CRON` —
  сроки хранения и расписание очистки (ЧТЗ 7, п. 8);
- `NOTIFICATIONS_HEALTH_LAG_THRESHOLD_SECONDS`, `NOTIFICATIONS_HEALTH_PENDING_THRESHOLD` — пороги `degraded` в `/health`;
- `NOTIFICATIONS_PREFERENCES_DEFAULT_TIMEZONE`, `NOTIFICATIONS_PREFERENCES_CHANNEL_PRIORITY`, `NOTIFICATIONS_PREFERENCES_MAX_FALLBACK_DEPTH`, `NOTIFICATIONS_FALLBACK_ERROR_CODES`, `NOTIFICATIONS_CRITICAL_EVENT_TYPES`, `NOTIFICATIONS_QUIET_HOURS_CHANNELS` — предпочтения, порядок цепочки, предел замещения, коды детерминированного отказа, критичные события и откладываемые каналы (M12);
- `NOTIFICATIONS_SENDER_EVENT_TYPES`, `NOTIFICATIONS_COURIER_EVENT_TYPES`, `NOTIFICATIONS_DISPATCHER_EVENT_TYPES`, `NOTIFICATIONS_ADMIN_EVENT_TYPES` — наборы типов событий по ролям для экрана настроек;
- `NOTIFICATIONS_TELEGRAM_BOT_TOKEN`, `NOTIFICATIONS_TELEGRAM_BOT_USERNAME` — токен и имя бота: только из окружения, в репозитории пусты (FR-NTF-441);
- `NOTIFICATIONS_TELEGRAM_CLIENT`, `NOTIFICATIONS_TELEGRAM_API_URL`, `NOTIFICATIONS_TELEGRAM_TIMEOUT_SECONDS`, `NOTIFICATIONS_TELEGRAM_RETRIES`, `NOTIFICATIONS_TELEGRAM_MESSAGE_LIMIT`, `NOTIFICATIONS_TELEGRAM_RETRY_AFTER_DEFAULT_SECONDS` — клиент Bot API, таймауты и лимиты сообщения;
- `NOTIFICATIONS_TELEGRAM_UPDATES_MODE`, `NOTIFICATIONS_TELEGRAM_POLL_TIMEOUT_SECONDS`, `NOTIFICATIONS_TELEGRAM_POLL_BATCH_SIZE`, `NOTIFICATIONS_TELEGRAM_POLL_INTERVAL_SECONDS`, `NOTIFICATIONS_TELEGRAM_WEBHOOK_SECRET`, `NOTIFICATIONS_TELEGRAM_WEBHOOK_URL` — режим получения апдейтов и параметры вебхука;
- `NOTIFICATIONS_TELEGRAM_LINK_TOKEN_TTL_SECONDS`, `NOTIFICATIONS_TELEGRAM_LINK_TOKEN_RETENTION_DAYS` — срок жизни токена привязки и срок хранения использованных токенов;
- `NOTIFICATIONS_PUSH_FAKE_FAIL_RECIPIENTS` — получатели, которым фейковый push-провайдер детерминированно отказывает: так проверяется цепочка `push → telegram → email`;
- `NOTIFICATIONS_PUSH_PROVIDER` — `fake` (по умолчанию) или `fcm`; неизвестное значение — ошибка конфигурации (M13);
- `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH`, `FCM_TIMEOUT_SECONDS`, `FCM_RETRY_TIMES`, `FCM_RETRY_SLEEP_MS` — реальный провайдер FCM: идентификатор проекта, путь к service account JSON и параметры вызова; креды только из окружения, в репозитории пусты;
- `NOTIFICATIONS_DLQ_RETENTION_DAYS`, `NOTIFICATIONS_DLQ_PAGE_LIMIT`, `NOTIFICATIONS_DLQ_MAX_PAGE_LIMIT` — срок хранения и лимиты порций очереди необработанных;
- `NOTIFICATIONS_DEVICE_TOKEN_RETENTION_DAYS` — срок хранения отозванных device-токенов (отдельной команды очистки нет, активные токены не чистятся);
- `NOTIFICATIONS_METRICS_ENABLED`, `NOTIFICATIONS_METRICS_SNAPSHOT_TTL_SECONDS`, `NOTIFICATIONS_METRICS_HISTOGRAM_BUCKETS`, `NOTIFICATIONS_METRICS_SNAPSHOT_CRON`, `NOTIFICATIONS_METRICS_CACHE_STORE`, `NOTIFICATIONS_METRICS_CACHE_PREFIX` — экспорт `/metrics`, TTL и границы гистограммы, частота снимка gauge и общий кэш;
- `NOTIFICATIONS_TRANSPORT` — `redis` (по умолчанию) или `rabbitmq`; неизвестное значение останавливает процесс (M13);
- `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_VHOST`, `RABBITMQ_HEARTBEAT`, `RABBITMQ_TIMEOUT_SECONDS`, `RABBITMQ_PREFETCH`, `RABBITMQ_MESSAGE_TTL_SECONDS`, `RABBITMQ_MAX_ATTEMPTS`, `RABBITMQ_EXCHANGE`, `RABBITMQ_DLX_EXCHANGE`, `RABBITMQ_DLQ_QUEUE` — подключение и топология брокера; пароль только из окружения.

`telegram` не входит в `NOTIFICATIONS_ENABLED_CHANNELS` по умолчанию: канал включается осознанно, вместе с токеном бота и именем. Если канал включён без токена или имени, самопроверка при старте останавливает процесс сообщением без секретов (FR-OPS-423).

## Запуск в docker compose

Из каталога `courier/`:

```sh
docker compose build notifications-app
docker compose up -d notifications-app notifications-worker notifications-scheduler
docker compose --profile telegram up -d notifications-telegram   # канал Telegram включён
docker compose --profile broker up -d rabbitmq                  # опциональный брокер событий (M13)
```

Процесс бота вынесен в профиль `telegram`: без этого профиля контейнер не запускается, поэтому при выключенном канале нет цикла падений. Токен и имя бота передаются из окружения хоста; вебхук вместо long polling включается `NOTIFICATIONS_TELEGRAM_UPDATES_MODE=webhook` и командой `notifications:telegram:webhook --set`.

Контейнеры:

- `notifications-app` — веб-процесс API на порту `8081` (`php artisan serve` в контейнере, порт 8000);
- `notifications-worker` — потребитель потоков (`notifications:consume`);
- `notifications-telegram` — процесс бота (`php artisan notifications:telegram:poll`), только под профилем compose `telegram`: long polling идёт отдельно от поллера отправки и не задерживает её;
- `notifications-scheduler` — расписание (`php artisan schedule:work`): поллер
  `notifications:send --once` раз в минуту, публикатор Outbox, очистка журнала, DLQ и токенов,
  снимок метрик `notifications:metrics-snapshot`;
- `rabbitmq` — опциональный брокер (`rabbitmq:3.13-management-alpine`), только под профилем
  compose `broker`: management UI на `15672`. Без профиля сервис работает на Redis.

Вместо отдельного nginx сервис на этапе каркаса использует встроенный сервер
Laravel: панелей и статики у сервиса нет. При появлении реальной нагрузки
контейнер переключается на связку `php-fpm` + nginx без изменения кода.

Один образ (`courier-notifications:local`, стадия `runtime`) используют все три
контейнера; каталог кода монтируется с хоста, зависимости лежат в томе
`notifications-vendor`, поэтому dev-пакеты (Pint, PHPStan, Pest) доступны внутри
контейнера. Стадия `dev` (`docker build --target dev .`) содержит полный набор
зависимостей для CI-сборок без монтирования.

## Своя база и права

База и роль создаются init-скриптом `courier/docker/pgsql/init/02-create-notifications-db.sql`:
база `notifications`, тестовая `notifications_testing`, роль `notifications`
с правами только на эти базы. У роли отозван `CONNECT` к базе монолита
(`courier`), поэтому таблицы `users`, `delivery_orders`, `assignments`,
`outbox_messages` недоступны ей ни при каких запросах (FR-NTF-213).

### Важно: init-скрипты выполняются только на пустом томе `pgdata`

PostgreSQL запускает файлы из `docker-entrypoint-initdb.d` только при
инициализации пустого каталога данных. Если том `pgdata` уже существует
(обычная ситуация в рабочей копии), база и роль не появятся сами.

Вариант 1 — пересоздать dev-окружение (**удаляет все данные dev-базы**):

```sh
cd courier
docker compose down -v && docker compose up -d
```

Вариант 2 — создать базу и роль на существующем томе без потери данных:

```sh
cd courier
docker compose exec -T pgsql psql -U courier -d courier < docker/pgsql/init/02-create-notifications-db.sql
```

Скрипт идемпотентным не является: если роль или база уже есть, PostgreSQL
сообщит об ошибке — тогда выполните только недостающие операторы.

После создания базы роль сервиса может применить свои миграции:

```sh
docker compose exec notifications-app php artisan migrate --force
```

## Таблицы сервиса

Схема повторяет ЧТЗ 7.1 и защищает правила сама (`database/migrations/`):

| Таблица | Что хранит | Ключевые ограничения |
|---|---|---|
| `notifications` | журнал уведомлений: событие, заказ, код трекинга, канал, шаблон, состояние, попытки; M12 добавил `deferred_until`, `next_attempt_at`, `fallback_from_notification_id` | уникальный `(event_id, recipient_id, channel)`, CHECK статусов `created/queued/sent/error/failed`, частичные индексы по `deferred_until` и `next_attempt_at` |
| `notification_attempts` | попытки отправки, только добавление | `(notification_id, attempt)` уникален, `cascadeOnDelete` |
| `notification_dedup` | захват ключа дедупликации | уникальный `(event_id, recipient_id, channel)` |
| `inbox_messages` | обработанные сообщения потока | уникальный `(message_id, consumer)` |
| `outbox_messages` | обратные события сервиса | `message_id` уникален и выдаётся базой, частичный индекс по неопубликованным |
| `recipient_contacts` | email и язык получателя | первичный ключ `user_id` |
| `channel_bindings` | привязки каналов, включая Telegram | одна активная привязка на `(user_id, channel)` (частичный уникальный индекс), CHECK каналов включает `telegram` |
| `push_messages` | журнал фейкового push | `notification_id` обнуляется при удалении |
| `notification_preferences` (M12) | предпочтения получателя по типу события: каналы, окно тихих часов, локаль, `critical_override` | уникальный `(user_id, event_type)`, индекс `user_id`, структурные CHECK `jsonb` |
| `telegram_link_tokens` (M12) | одноразовые токены привязки бота | уникальный `token_hash` (SHA-256), индексы `(user_id, created_at DESC)` и `(expires_at)`, `used_at` |
| `dlq_entries` (M13) | очередь необработанных по каналам: снимок терминального отказа без тела и персональных данных | уникальный `(notification_id, channel)`, индексы `(channel, failed_at DESC)`, `(state, failed_at)` и `resolved_at`, CHECK `open/replayed/resolved`, внешнего ключа на `notifications` нет |
| `device_tokens` (M13) | токены устройств для реального push | уникальный `token`, частичный индекс `(user_id) WHERE revoked_at IS NULL`, индекс `(platform)`, `fingerprint` для логов |

Внешних ключей на таблицы монолита нет: `order_id` и `recipient_id` — просто
идентификаторы из события (ЧТЗ 7, п. 2). Email хранится только в
`recipient_contacts` и `channel_bindings`, `chat_id` — только в
`channel_bindings.address`, открытого токена привязки в базе нет (ЧТЗ 7, п. 7).
Все моменты времени — `timestamptz` в UTC, `notification_attempts` и
`push_messages` не переписываются.

Guard-тесты схемы: `tests/Feature/SchemaGuardTest.php`,
`tests/Feature/PreferenceSchemaGuardTest.php`, `tests/Feature/TelegramSchemaGuardTest.php`;
уникальность, индексы и отсутствие внешнего ключа у `dlq_entries` проверяет `tests/Feature/DlqTest.php`,
токены устройств — `tests/Feature/DeviceTokenApiTest.php`.

## Политика каналов и тексты

Матрица «событие → роль получателя → каналы» живёт в `config/notifications.php`
сервиса (`policy`, `requires_assignment`, `enabled_channels`) и повторяет правила
M7 монолита без расширения (FR-NTF-241, FR-NTF-242):

| Событие | Отправитель | Курьер |
|---|---|---|
| `order.created` | email | — |
| `order.assigned` | push | push |
| `order.picked_up` | email | — |
| `order.delivered` | email, push | — |
| `order.failed` | email | — |
| `order.cancelled` | email | push (при наличии назначения) |

- Политику собирает `App\Services\Notifications\NotificationPolicyFactory`, а
  домен получает готовый неизменяемый объект: конфигурацию читает слой сервисов.
- Каналы читаются из конфигурации: выключенный канал отбрасывается без
  уведомления и без ошибки (FR-NTF-245). Каналы события независимы (FR-NTF-244).
- Резолвер `NotificationPolicyResolver::resolve([])` остаётся политикой по умолчанию, а `resolveFor()` учитывает сохранённые предпочтения получателя (раздел «Предпочтения получателя»).
- События без правил (смены курьера) подтверждаются и журнала не пополняют (FR-NTF-243).
- Шаблон выбирается парой «событие — канал» (`App\Domain\Notifications\NotificationTemplate`),
  тексты лежат в `lang/ru/notifications.php`: номер заказа, статус и ссылка
  трекинга; имени, адреса, телефона и email получателя в текстах нет
  (FR-NTF-247, FR-NTF-251, FR-NTF-258). Неизвестный шаблон — бизнес-исключение
  `NotificationTemplateNotFound` без автоматического повтора.
- Локаль берётся из события, неподдерживаемая заменяется языком по умолчанию
  (`NOTIFICATIONS_LOCALE_FALLBACK`, по умолчанию `ru`).

### Как определяется курьер для `order.cancelled`

Payload отмены не содержит назначения (ЧТЗ 8.3, п. 4), поэтому курьер ищется
в собственной базе сервиса: берётся самая поздняя строка `notifications` этого
заказа с `recipient_role = courier` и её `recipient_id`. Если таких строк нет,
назначения у заказа не было — push курьеру не создаётся, остаётся только письмо
отправителю. Решение зафиксировано комментарием в
`App\Services\Notifications\RecipientResolver`.

## Предпочтения получателя (M12)

Источник истины — `notification_preferences`: одна строка на пару «пользователь — тип события». Строки нет — действует матрица M11 для роли получателя; это и есть дефолт, отдельной копии матрицы нет (`NotificationPolicyFactory` — единственное место дефолта).

- `GET /api/v1/preferences?user_id=&role=` отдаёт эффективные строки с источником (`default` или `user`) и необязательным окном тихих часов; необязательный `role` сужает набор до типов событий роли, без роли возвращается объединение наборов.
- `PATCH /api/v1/preferences` проверяет весь набор и пишет его в одной транзакции: при ошибке не сохраняется ни одна строка. `reset: true` удаляет строку и возвращает дефолт.
- `App\Domain\Notifications\PreferenceValidator` проверяет каналы (подмножество `enabled_channels`), локаль (`locales.supported`), окно (`HH:MM`, таймзона из `DateTimeZone::listIdentifiers()`, `from != to`) и критичность: `order.delivered` и `order.failed` нельзя оставить без email — `422 PREFERENCE_CRITICAL_CHANNEL_REQUIRED`.
- `channels: []` у некритичного события — «не уведомлять»: строки журнала и обратного события не создаются.
- Наборы типов событий по ролям задаёт конфигурация: `sender_event_types`, `courier_event_types`, `dispatcher_event_types`, `admin_event_types`; у диспетчера и администратора наборы пусты.
- Изменение предпочтений не пересчитывает журнал и не переносит отложенные строки.

## Цепочка каналов и замещение (M12)

`App\Services\Notifications\NotificationPolicyResolver::resolveFor()` собирает каналы получателя: сохранённое предпочтение авторитетно, иначе — каналы матрицы для роли. Затем `Channels\ChannelAvailability` отбрасывает выключенные и заранее недоступные каналы (нет email-контакта, нет активной привязки Telegram), а итог упорядочивается по `preferences.channel_priority`; email всегда последний.

- Каналы выбраны, но все недоступны: одна строка `failed` с `NOTIFICATION_CHANNEL_UNAVAILABLE` и обратное событие `notification.failed`.
- Детерминированный отказ канала (`TELEGRAM_CHAT_NOT_FOUND`, `TELEGRAM_REQUEST_REJECTED`, `NOTIFICATION_CHANNEL_UNAVAILABLE`, `NOTIFICATION_PROVIDER_REJECTED` — перечень в `preferences.fallback_error_codes`) переводит строку в `failed`, после чего `App\Services\Notifications\ChannelFallback` создаёт строку следующего канала цепочки в `created` со ссылкой `fallback_from_notification_id`.
- Инфраструктурный сбой (`429`, `5xx`, таймаут) замещение не запускает: строка уходит в `error` и повторяется по backoff.
- Глубина цепочки не хранится: `App\Domain\Notifications\FallbackChain` считает её по ссылкам, предел — `preferences.max_fallback_depth`. Ключ дедупликации захватывается до вставки, поэтому второй строки того же канала не появляется.
- `400 chat not found` и `403 bot was blocked by the user` дополнительно отзывают привязку Telegram.

## Тихие часы (M12)

Окно — часть строки предпочтения (`quiet_hours: {from, to, timezone}`); `from > to` означает окно через полночь. Момент окончания окна считает `App\Domain\Notifications\QuietHours::deferredUntil()` в таймзоне получателя и возвращает UTC; `OrderEventProcessor` пишет его в `notifications.deferred_until` при создании строки и больше не пересчитывает.

- Откладываются каналы из `preferences.quiet_hours_channels` (`push,telegram`); email уходит сразу.
- Критичные типы событий (`preferences.critical_event_types`) окно игнорируют.
- Поллер пропускает строку, пока `deferred_until` в будущем, и отправляет её после окна без дублей.
- Отложенная строка увеличивает счётчик `deferred` и пишется в журнал.

## Канал Telegram и бот (M12)

- `App\Domain\Notifications\NotificationChannel::Telegram`, шаблоны `order_*_telegram` в `lang/{locale}/notifications.php`, адрес — `chat_id` активной привязки `channel_bindings`.
- Сеть скрыта за контрактом `Channels\Contracts\TelegramBotClient`: `HttpTelegramBotClient` в работе, `FakeTelegramBotClient` в тестах и демонстрации, выбор — `TelegramBotClientFactory` по `telegram.client` (`http` или `fake`).
- `Channels\TelegramChannel` собирает текст из тех же lang-шаблонов, соблюдает `telegram.message_limit` (4096) и сохраняет ссылку трекинга; ответы Bot API разбирает `Telegram\TelegramResponseMapper`, `retry_after` лимита пишется в `notifications.next_attempt_at`.
- Привязка: `POST /api/v1/channel-bindings/telegram/start` выпускает одноразовый токен (в базе только SHA-256-хеш) и deep-link; бот подтверждает его командой `/start <token>`; активная привязка одна, прежняя строка остаётся с `revoked_at`; `DELETE` отвязывает канал и сохраняет историю.
- Апдейты: `notifications:telegram:poll` (long polling) или `POST /api/v1/telegram/webhook` с секретом; оба режима вызывают один `Telegram\UpdateHandler`, а маршрут вебхука регистрируется только при `telegram.updates_mode = webhook`.

## Потребитель событий

Процесс `notifications:consume` читает поток заказов через consumer group сервиса
(`XREADGROUP` пачкой), проверяет конверт по общей JSON Schema и выполняет эффект:

1. `INSERT inbox_messages (message_id, consumer)` — захват ключа «сообщение — потребитель»;
2. `INSERT notification_dedup (event_id, recipient_id, channel)` — захват ключа дедупликации;
3. `INSERT notifications` в состоянии `created`;
4. COMMIT и только после него `XACK` (FR-NTF-261, FR-NTF-265).

Конфликт уникального индекса — пропуск без ошибки и без второго эффекта
(FR-NTF-262…264): проверок «есть ли уже» перед вставкой нет. Сообщения,
ожидающие дольше `NOTIFICATIONS_PENDING_IDLE_SECONDS`, возвращаются через
`XAUTOCLAIM`, когда новых сообщений нет. Недействительный конверт (payload не
по схеме, неизвестный тип или версия) отмечается в `inbox_messages` статусом
`invalid` с кодом причины и подтверждается без эффекта (FR-NTF-225).

```sh
docker compose exec -T notifications-worker php artisan notifications:consume --once --json
docker compose exec -T notifications-app php artisan notifications:consume --once --limit=10 --stream=events.orders
```

Коды возврата: `0` — пачка обработана, `1` — есть ошибки обработки или транспорт
недоступен, `2` — ошибка конфигурации. В выводе только счётчики и имя потока.
Без `--once` команда работает циклом и завершается по `SIGTERM` (если доступно
расширение `pcntl`); неподтверждённое сообщение остаётся в pending и будет
выдано повторно.

Долгоживущий процесс обслуживает оба потока: каждый цикл читает поток заказов,
затем поток контактов. У потоков разные получатели в группе
(`NOTIFICATIONS_CONSUMER_NAME` и `NOTIFICATIONS_CONTACTS_CONSUMER_NAME`),
поэтому подтверждения и pending одного потока не влияют на другой. Ключ
`--once` обрабатывает один поток: по умолчанию заказы, поток контактов —
`--stream=events.contacts`.

## Контакты получателей

Контакты приходят отдельным потоком `events.contacts` событием
`user.contact_changed` (схема `user-contact-changed.v1`): `user_id`, `email`,
`locale`, `occurred_at`, `correlation_id`. Это единственный контракт
с персональными данными: он не публикуется в поток заказов, не логируется
и не попадает в обратные события (FR-NTF-231, FR-NTF-232).

Эффект `App\Services\Notifications\RecipientContactSync`:

1. отметка «сообщение обработано» пишется в `inbox_messages` в одной транзакции;
2. `recipient_contacts` обновляется upsert-ом по `user_id` — повтор события
   не создаёт вторую строку, `source_event_id` показывает последнее событие;
3. активная привязка `channel_bindings` канала email получает новый адрес —
   заготовка M12; отправке M11 она не нужна.

Порядок «Inbox → эффект → ack» тот же, что у потока заказов, поэтому проход
общий (`App\Services\Events\EventStreamConsumer`), а отличается только
эффект. Повторная доставка, повторная публикация и гонка не создают второго
контакта; конфликт уникального ключа — пропуск без ошибки (FR-NTF-233).

```sh
docker compose exec -T notifications-worker php artisan notifications:consume --once --stream=events.contacts --json
```

Почта из события не попадает ни в логи, ни в сообщения об ошибках: в журнал
пишутся только идентификатор пользователя и тип события (FR-NTF-235).
Отсутствие контакта при отправке — бизнес-причина
`NOTIFICATION_RECIPIENT_UNKNOWN`: уведомление переходит в `failed` без
автоматического повтора (FR-NTF-236).

## Отправка писем и push

Слой отправки отделён от потребителя и от поллера: одна попытка не знает,
когда её вызовут. Классы ошибок разделены по FR-NTF-271, а каждая попытка
пишется в журнал `notification_attempts` (см. следующий раздел).

- `App\Services\Notifications\NotificationSender` — одна попытка: строка
  переводится `created`/`error` → `queued` → `sent`, `error` или `failed`;
  увеличивается `attempts`, при успехе заполняется `sent_at`, причина
  сохраняется в `last_error_code`. Терминальные строки автоматически
  не отправляются повторно.
- `App\Services\Notifications\ChannelSender` — разбор канала и шаблона
  из строки журнала. Каналы независимы: сбой email не отменяет push, потому
  что у каждого канала своя строка и свой вызов (FR-NTF-244).
- `Channels\EmailChannel` — адрес берётся из `recipient_contacts` в момент
  отправки, поэтому смена почты применяется к ещё не отправленным
  уведомлениям (FR-NTF-233, FR-NTF-252). Письмо `App\Mail\NotificationMail`
  собирается из lang-шаблонов и отправляется через Mailer SMTP/Mailpit:
  тема и тело содержат номер заказа, статус и ссылку трекинга
  (`NOTIFICATIONS_TRACKING_URL` + `tracking_code`), но не имя, адрес,
  телефон и email получателя (FR-NTF-251, FR-NTF-258).
- `Channels\PushChannel` + контракт `Channels\PushProvider` — две реализации (M13):
  `Channels\FakePushProvider` пишет сообщение в `push_messages` и не ходит в сеть,
  `Channels\FcmPushProvider` отправляет FCM HTTP v1 `messages:send` по активным
  device-токенам получателя. Сбой записи журнала фейка — инфраструктурная ошибка:
  строка уходит в `error` с кодом `NOTIFICATION_SEND_FAILED` и будет повторена
  (FR-NTF-256). Подробности реального провайдера — в разделе «Push FCM и токены
  устройств».
- Ключ `NOTIFICATIONS_PUSH_PROVIDER` принимает `fake` (по умолчанию), пустое
  значение или `fcm`; неизвестное значение останавливает отправку ошибкой
  конфигурации, а `fcm` без идентификатора проекта и пути к service account —
  тоже (FR-NTF-522, FR-NTF-524).
- `Channels\TelegramChannel` — третий канал: правило сборки текста, лимит
  сообщения и разбор отказов Bot API описаны в разделе «Канал Telegram и бот».

Литеральных текстов в коде отправки нет: тема и строки тела приходят из
`lang/{locale}/notifications.php`, локаль письма — из контакта с фолбэком на
язык текстов сервиса, локаль push — язык текстов (FR-NTF-247).

## Повторы, попытки и необработанные

`php artisan notifications:send` — поллер отправки (FR-NTF-272…277).

- **Выборка.** Берутся уведомления в `created` и `error`, а также брошенные
  `queued`, у которых `updated_at` старше `NOTIFICATIONS_SENDER_RETRY_AFTER_SECONDS`.
  Готовность по backoff считается по последней строке `notification_attempts`
  и ряду `NOTIFICATIONS_BACKOFF_SECONDS` (элемент по номеру попытки, последний
  повторяется) плюс детерминированный разброс до
  `NOTIFICATIONS_BACKOFF_JITTER_RATIO` (по умолчанию 20%); уведомление без
  попыток готово сразу. Последняя попытка ищется коррелированным подзапросом
  по уникальному индексу `(notification_id, attempt)`: один запрос на пачку,
  без N+1 и без перебора всей таблицы попыток.
- **Плановые задержки.** Две колонки означают «не раньше момента»:
  `next_attempt_at` — `retry_after` лимита Telegram (значение снимается при
  успехе и бизнес-отказе), `deferred_until` — окончание окна тихих часов,
  посчитанное при создании строки. Пока любой из моментов в будущем, строку
  не берут; оба условия опираются на частичные индексы.
- **Попытки.** `notification_attempts` только дополняется: номер попытки,
  статус, класс причины (`business`/`infrastructure`/`program`, а не FQCN),
  код, безопасное усечённое сообщение (`NOTIFICATIONS_ATTEMPT_MESSAGE_LIMIT`)
  и `provider_code`, если канал его передал. Email и телефон вычищаются
  санитайзером.
- **Классы ошибок.** Бизнес-причина (нет контакта, нет шаблона) и
  программная ошибка (`Error`) переводят уведомление в `failed` сразу;
  инфраструктурный сбой — в `error` и повтор по backoff; после
  `NOTIFICATIONS_MAX_ATTEMPTS` — в `failed` с сохранённой причиной.
- **Замещение.** После детерминированного отказа канала `ChannelFallback`
  создаёт строку следующего канала цепочки со ссылкой
  `fallback_from_notification_id`; инфраструктурные сбои замещение не
  запускают (раздел «Цепочка каналов и замещение»).
- **Изоляция.** Пачка обрабатывается построчно: сбой одной строки не
  отменяет отправку остальных.
- **Ручной повтор.** `POST /api/v1/notifications/{id}/retry` переводит
  `failed` в `error` (та же строка, новый строки попытки нет) и помечает
  запись готовой к отправке: строка обновлена после последней попытки,
  поэтому пауза backoff не действует. Счётчик попыток не сбрасывается —
  повтор разрешён сверх лимита. Повтор отправленного — `409`.

Ключи команды: `--once` — один проход, `--limit=` — размер пачки
(`NOTIFICATIONS_SENDER_BATCH_SIZE`), `--id=` — адресная отправка,
`--json` — машиночитаемый вывод. Без `--once` команда работает циклом с
паузой `NOTIFICATIONS_SENDER_POLL_INTERVAL_SECONDS` и завершает текущий
проход по SIGTERM/SIGINT (при наличии `pcntl`). В docker compose проход
запускается из контейнера расписания (`routes/console.php`, раз в минуту),
долгоживущий цикл можно включить отдельным процессом.

```sh
docker compose exec -T notifications-app php artisan notifications:send --once --json
docker compose exec -T notifications-app php artisan notifications:send --id=42
```

Результат: `created|error` → `queued` → `sent`/`error`/`failed`; `attempts`
увеличивается, `sent_at` заполняется только при успехе, причина остаётся
в `last_error_code`. Моменты времени пишутся в UTC, персональных данных
в выводе и логах нет.

## Очередь необработанных (M13)

Терминальный отказ канала (`notifications.status=failed`) оставляет запись в
`dlq_entries` — отдельной таблице сервиса, одна запись на пару
`(notification_id, channel)`. Запись создаёт `App\Services\Notifications\DlqEntryWriter`
в **той же транзакции**, что статус, попытка и событие Outbox (вызов из
`NotificationSender::finalize()`), поэтому «`failed` без записи» не бывает.
Успешная отправка переводит запись того же канала в `resolved` в той же
транзакции.

| Состояние | Смысл | Переход |
|---|---|---|
| `open` | отказ зафиксирован | повтор → `replayed`; истечение срока — удаление командой |
| `replayed` | повтор поставлен, строка журнала снова в `error` | успех → `resolved`; новый отказ → `open` |
| `resolved` | строка журнала стала `sent` | терминальное, повтор отклоняется |

- Снимок записи: `event_id`, `event_type`, `order_id`, `recipient_id`,
  `recipient_role`, `channel`, `error_class`, `error_code`, `attempts`,
  `correlation_id`, `failed_at`; тела сообщения, email, телефона и токена нет.
- Дедупликация — уникальный индекс `(notification_id, channel)`; вставка идёт
  `upsert`, проверки «есть ли уже» нет.
- Повтор (`App\Services\Notifications\DlqService::replay()`) возвращает
  **ту же** строку журнала в `error` с плановой задержкой backoff, увеличивает
  `replay_count` и `last_replayed_at`; второго уведомления и второй записи нет.
- Повтор разрешён только из `open`; разобранная запись — `409` с кодом
  `DLQ_ENTRY_ALREADY_RESOLVED`, отсутствующая — `404` с `DLQ_ENTRY_NOT_FOUND`.
- Отказ одного канала не блокирует остальные: уведомления события остаются
  разными строками журнала.
- Очистка — срок `NOTIFICATIONS_DLQ_RETENTION_DAYS` (90 дней) командой
  `notifications:dlq-purge` и по расписанию; журнал уведомлений не трогается.

```sh
docker compose exec -T notifications-app php artisan notifications:dlq-list --channel=push --state=open --json
docker compose exec -T notifications-app php artisan notifications:dlq-replay 7 --json
docker compose exec -T notifications-app php artisan notifications:dlq-purge --json
```

Коды возврата: `dlq-list` — 0 список построен, 2 ошибка; `dlq-replay` — 0 повтор
поставлен, 1 записи нет или повтор недопустим, 2 ошибка; `dlq-purge` — 0 очистка
выполнена, 2 ошибка.

## Метрики (M13)

`GET /metrics` отдаёт `text/plain; version=0.0.4` за сервисным токеном и читает
готовый снимок, а не пересчитывает базу:

| Метрика | Тип | Метки |
|---|---|---|
| `notifications_sent_total` | counter | `channel` |
| `notifications_failed_total` | counter | `channel` |
| `notifications_retried_total` | counter | `channel` |
| `notifications_send_duration_seconds` | histogram | `channel`, `le` |
| `notifications_dlq_depth` | gauge | `channel`, `state` |
| `notifications_consumer_lag_seconds` | gauge | `stream` |
| `notifications_consumer_pending` | gauge | `stream` |
| `notifications_outbox_oldest_unpublished_seconds` | gauge | — |

- Счётчики и гистограмма инкрементируются в процессе отправки
  (`NotificationCounters::recordSent/recordFailed/recordRetried/observeDuration`)
  и сбрасываются в общий кэш (`NOTIFICATIONS_METRICS_CACHE_STORE`, по умолчанию
  Redis) методом `flushToCache()`.
- «Дорогие» gauge — глубину DLQ, лаг и pending потоков, возраст старейшего
  неопубликованного события Outbox — считает задача `notifications:metrics-snapshot`
  по `NOTIFICATIONS_METRICS_SNAPSHOT_CRON` с TTL
  `NOTIFICATIONS_METRICS_SNAPSHOT_TTL_SECONDS`.
- Метки закрыты белым списком (`metrics.labels`), идентификаторы получателей и
  заказов в экспорт не попадают.
- Без токена — `401`, при `NOTIFICATIONS_METRICS_ENABLED=false` — `404`, оба в
  `application/problem+json`; недоступный источник не роняет сервис — отдаётся
  то, что есть, деградация пишется в журнал кодом `METRICS_UNAVAILABLE`.
- Ограничение: лаг и pending считаются по consumer group Redis Streams
  независимо от включённого транспорта; состояние брокера показывают `/health`
  и `notifications:broker-health`.

```sh
docker compose exec -T notifications-app php artisan notifications:metrics-snapshot --refresh --json
curl -s -H "Authorization: <сервисный токен>" http://127.0.0.1:8081/metrics
```

## Push FCM и токены устройств (M13)

Провайдер выбирает `Channels\PushProviderFactory` по `NOTIFICATIONS_PUSH_PROVIDER`:

- `fake` (по умолчанию) — `FakePushProvider`, журнал `push_messages`, сети нет;
  получателям из `NOTIFICATIONS_PUSH_FAKE_FAIL_RECIPIENTS` он детерминированно
  отказывает;
- `fcm` — `FcmPushProvider`: FCM HTTP v1 `messages:send` через фасад `Http` без
  SDK. Таймаут и число повторов — `FCM_TIMEOUT_SECONDS`, `FCM_RETRY_TIMES`;
  повторяются только идемпотентные ответы (`429`, `5xx`, таймаут), `Retry-After`
  учитывается в пределах лимита и передаётся как `next_attempt_at`.
- Ответ `404`/`410` с причиной `UNREGISTERED` отзывает токен и даёт
  `NOTIFICATION_DEVICE_UNREGISTERED`, после чего работает замещение каналов;
  отсутствие активного токена — `NOTIFICATION_PUSH_TOKEN_MISSING`, DLQ по этой
  причине не создаётся.
- Токен доступа OAuth2 получает `ServiceAccountFcmTokenSource` по service account
  JSON (`FCM_CREDENTIALS_PATH`); в журнал попадают только `fingerprint` и
  `platform`.

Токены устройств ведутся служебным API сервиса:

| Метод и путь | Ответы |
|---|---|
| `POST /api/v1/device-tokens` | `201` — создан, `200` — обновлён, `422` — ошибка полей (`DEVICE_TOKEN_INVALID`) |
| `DELETE /api/v1/device-tokens/{id}` | `204` — отозван, `202` — уже отозван, `404` — не найден (`DEVICE_TOKEN_UNKNOWN`) |

Токен уникален глобально; повторная регистрация обновляет владельца, платформу,
локаль, версию и `last_seen_at`, а ранее отозванный токен снова становится
активным. В ответах токен не возвращается.

## Брокер как опция (M13)

`NOTIFICATIONS_TRANSPORT` выбирает адаптер: `redis` (по умолчанию) или `rabbitmq`
(`App\Services\Events\TransportDriver`); неизвестное значение останавливает
процесс. Для брокера `RabbitMqEventStream` реализует контракт `EventStream` и
`DeadLetterRedriver`.

- Топология из конфигурации: exchange типа `topic`, ключ маршрутизации «домен +
  полный тип события», очередь потребителя `<поток>.<группа>`, привязка
  `<домен>.#`, `prefetch` (`RABBITMQ_PREFETCH`), per-message и queue TTL,
  `RABBITMQ_DLX_EXCHANGE` и `RABBITMQ_DLQ_QUEUE`.
- `ack` — только после фиксации эффекта; инфраструктурная ошибка — `nack`/`requeue`;
  после `RABBITMQ_MAX_ATTEMPTS` сообщение уходит в DLX. Второй модели разбора нет:
  потребитель возвращает сообщения DLQ в основной поток (`x-redriven`), дальше
  работают Inbox, дедупликация и `dlq_entries`.
- `/health` добавляет проверку `broker` только при `rabbitmq`; недоступность даёт
  `503`/`degraded`. `notifications:broker-health --json` отдаёт статус, очереди и
  DLQ, код `0` — норма или транспорт выключен, `1` — брокер недоступен.
- `php-amqplib` v3 требует расширения `ext-sockets`; образ ставит `sockets`.
- Брокер поднимается профилем compose `broker`; без профиля основной запуск идёт
  на Redis. Совпадение поведения адаптеров проверяет `tests/Feature/TransportParityTest.php`.

## Обратные события и Outbox сервиса

Терминальный статус (`sent`/`failed`) в той же транзакции, что и смена
состояния уведомления, пишет обратное событие в `outbox_messages` сервиса:
`NotificationSent.v1` (`notification.sent`) или `NotificationFailed.v1`
(`notification.failed`). Payload повторяет ЧТЗ 8.3: `event_id` исходного
события заказа, `notification_id`, `order_id`, `recipient_id`, `channel`,
`status`, `attempt`, `occurred_at`, `correlation_id`, для отказа —
`error_code`; персональных данных нет. `correlation_id` события заказа
сохраняется в строке журнала (`notifications.correlation_id`) и
возвращается в обратном событии (FR-NTF-281, FR-OPS-201).

`notifications:publish-outbox` — публикатор Outbox по правилам монолита:
`FOR UPDATE SKIP LOCKED`, блокировка прохода, `attempts`, `available_at`,
`published_at` только после успешного `XADD` в `events.notifications`
(FR-NTF-283). Конверт проверяется по общей JSON Schema до `XADD`; событие,
не прошедшее проверку, остаётся в Outbox с кодом причины. Сбой публикации
не отменяет отправку: уведомление остаётся `sent`/`failed`, строка ждёт
повтора по backoff (FR-NTF-286). Интервал, пачка, попытки и задержки —
`NOTIFICATIONS_OUTBOX_PUBLISH_*`; проход запускается расписанием.

```sh
docker compose exec -T notifications-app php artisan notifications:publish-outbox --json
docker compose exec -T notifications-app php artisan notifications:publish-outbox --limit=50
```

Коды возврата: `0` — проход выполнен (в том числе пустой и при занятой
блокировке), `1` — есть неудачные публикации, `2` — ошибка.

## Служебный API

Все маршруты `/api/v1/**` закрыты сервисным токеном
`Authorization: Bearer <NOTIFICATIONS_API_TOKEN>`; сравнение постоянным по
времени (`hash_equals`). Без токена и с неверным токеном ответ одинаковый —
`401` без деталей; токен не логируется и не возвращается. `/health`
остаётся открытым (FR-OPS-206).

| Метод и путь | Ответ |
|---|---|
| `GET /api/v1/notifications` | `200` `{"data":[…],"next_cursor":…}` |
| `POST /api/v1/notifications/{id}/retry` | `202` строка журнала; `404`; `409` |
| `GET /api/v1/push-messages` | `200` `{"data":[…],"next_cursor":…}` |
| `GET /api/v1/dlq?channel=&state=&from=&to=&limit=&cursor=` | `200` постраничный список записей DLQ без персональных данных; `422` некорректный фильтр или лимит |
| `POST /api/v1/dlq/{id}/replay` | `202` состояние записи после повтора; `404 DLQ_ENTRY_NOT_FOUND`; `409 DLQ_ENTRY_ALREADY_RESOLVED` |
| `POST /api/v1/device-tokens` | `201` создан, `200` обновлён, `422 DEVICE_TOKEN_INVALID`; токен в ответе не возвращается |
| `DELETE /api/v1/device-tokens/{id}` | `204` отозван, `202` уже отозван, `404 DEVICE_TOKEN_UNKNOWN` |
| `GET /metrics` | `200` текст Prometheus 0.0.4; `401`; `404` при выключенном экспорте |
| `GET /api/v1/preferences?user_id=&role=` | `200` эффективные предпочтения с источником строки; необязательный `role` сужает набор; `422` некорректный `user_id` или неизвестная роль |
| `PATCH /api/v1/preferences` | `200` новое состояние; `422` коды полей (`PREFERENCE_INVALID_CHANNEL`, `PREFERENCE_INVALID_EVENT_TYPE`, `PREFERENCE_EVENT_TYPE_NOT_ALLOWED`, `PREFERENCE_INVALID_LOCALE`, `PREFERENCE_INVALID_QUIET_HOURS`, `PREFERENCE_CRITICAL_CHANNEL_REQUIRED`) |
| `POST /api/v1/channel-bindings/telegram/start` | `201` `{deep_link, expires_at, status}`; `422 TELEGRAM_CHANNEL_DISABLED` |
| `POST /api/v1/channel-bindings/telegram/confirm` | `200` привязка; `409 TELEGRAM_LINK_TOKEN_INVALID`; `410 TELEGRAM_LINK_TOKEN_EXPIRED` |
| `DELETE /api/v1/channel-bindings/telegram` | `204`; `409 TELEGRAM_BINDING_ABSENT` |
| `GET /api/v1/channel-bindings` | `200` статусы привязок без адресов |
| `POST /api/v1/telegram/webhook` | только при `updates_mode = webhook`; `200` для валидного секрета, `403 TELEGRAM_WEBHOOK_SECRET_INVALID` |

- Фильтры журнала: `status`, `channel`, `from`, `to` (ISO 8601), `limit`
  (`NOTIFICATIONS_API_PAGE_LIMIT`, не больше
  `NOTIFICATIONS_API_MAX_PAGE_LIMIT`). Строка ответа: идентификаторы,
  канал, шаблон, статус, попытки, `error_code` и моменты времени;
  адресов, телефонов и email нет (FR-NTF-275).
- Пагинация — ключевая по `id`: `next_cursor` — непрозрачный base64url
  идентификатора последней строки; следующая страница идёт по
  `id < cursor` в порядке убывания. Это простой вариант ЧТЗ 8.6 без
  дополнительных индексов и без пропусков при добавлении записей.
- `GET /api/v1/push-messages` отдаёт `id`, `notification_id`, `template`,
  `body`, `sent_at` — без адресов и device-токенов (FR-NTF-254).
- Ошибки — `application/problem+json` с полями `type`, `title`, `status`,
  `code` (ЧТЗ 8.5). Внутренние исключения и сообщения провайдеров наружу
  не передаются (FR-OPS-207).

```sh
curl -s -H "Authorization: Bearer $NOTIFICATIONS_API_TOKEN" \
  "http://localhost:8081/api/v1/notifications?status=failed&limit=20"
curl -s -X POST -H "Authorization: Bearer $NOTIFICATIONS_API_TOKEN" \
  "http://localhost:8081/api/v1/notifications/42/retry"
```

## Состояние сервиса

`GET /health` открыт без токена и отвечает только фактами:

```json
{"status":"ok","checks":{"database":"ok","redis":"ok","contracts":"ok"},
 "lag_seconds":0,"pending":0,"generated_at":"2026-11-06T10:00:00+03:00"}
```

`200` — база и Redis доступны и пороги не превышены; `503` — сбой зависимости
или превышение порога (значение `status` — `degraded`). Пороги задаются
`NOTIFICATIONS_HEALTH_LAG_THRESHOLD_SECONDS` и `NOTIFICATIONS_HEALTH_PENDING_THRESHOLD`.
Персональных данных, адресов, токенов и идентификаторов пользователей в ответе
нет (FR-OPS-210). Недоступный Redis не останавливает процесс.

При старте контейнера entrypoint выполняет `php artisan notifications:check`:
проверяются конфигурация, читаемость каталога схем и доступность базы;
некорректная конфигурация останавливает процесс с понятным сообщением
(FR-OPS-209). Redis на старте не проверяется — его сбой виден только в `/health`.

```sh
docker compose exec -T notifications-app php artisan notifications:check --json
curl -s http://localhost:8081/health
```

## Наблюдаемость

Журнал сервиса пишется в stderr одной JSON-записью на строку и содержит только
технические поля: `correlation_id`, `event_id`, `notification_id`, `order_id`,
`recipient_id`, `channel`, `status`, `attempt`, `error_code` (FR-OPS-202).
Адреса, телефоны, токены, тела писем и содержимое push в журнал не попадают:
белый список полей задан форматтером `App\Logging\TechnicalLogFormatter`.
Поле `count` — числовой счётчик операционного журнала (FR-OPS-208).

```json
{"timestamp":"2026-11-06T10:00:00+00:00","level":"info","message":"Попытка отправки уведомления завершена.","context":{"correlation_id":"req-0123456789abcdef","notification_id":501,"order_id":42,"recipient_id":7,"channel":"email","status":"sent","attempt":1}}
```

`correlation_id` сквозной (FR-OPS-201): монолит берёт `request_id` запроса и
кладёт его в `outbox_messages`, событие несёт его в поле `correlation_id` и в
`X-Request-Id` служебного вызова, сервис переносит его в строку журнала,
лог-контекст потребителя, отправки, публикатора Outbox и API, а обратное
событие возвращает его в монолит. Вне HTTP создаётся новый UUID.

Счётчики отправленных, необработанных и повторённых уведомлений по каналам
пишутся в журнал после пачки отправки записью с полями `channel`, `status`
(`sent`, `failed`, `retried`) и `count` (FR-OPS-208).

Событийные счётчики M12 ведутся в памяти процесса и инкрементируются в момент
события: `fallbacks` (срабатывания замещения по каналам), `deferred`
(отложенные тихими часами) и `telegramErrors` (ошибки бота по коду ответа:
`400`, `403`, `429`, `5xx`, `timeout`). Читаются агрегатом
`App\Services\Notifications\NotificationCounters` и пишутся в журнал; экспорт
`/metrics` и дашборды — M13. Поля журнала расширены только техническими
значениями: `user_id`, `event_type`, `retry_after`, `fallback_from`.

`/health` к Telegram не обращается; опциональная проверка Bot API —
`notifications:check --telegram` (метод `getMe`, без вывода токена).

### Очистка журнала

```sh
docker compose exec -T notifications-app php artisan notifications:purge --json
docker compose exec -T notifications-app php artisan notifications:purge --days=30 --json
```

Сроки хранения берутся из конфигурации: `inbox_messages` и `notification_dedup` —
30 дней, `notifications` и `notification_attempts` — 90, `push_messages` — 30
(ЧТЗ 7, п. 8). Флаг `--days` переопределяет только срок журнала уведомлений
разового прогона. Очистка не ломает ссылки: попытки удаляются вместе с
уведомлением каскадом, строка push-журнала переживает удаление уведомления
с обнулённой ссылкой. Расписание (`notifications-scheduler`) выполняет
`notifications:purge` по cron из `NOTIFICATIONS_PURGE_CRON`.

Токены привязки Telegram чистятся отдельной командой — у них свой срок хранения:

```sh
docker compose exec -T notifications-app php artisan notifications:telegram:purge --json
docker compose exec -T notifications-app php artisan notifications:telegram:purge --days=3 --json
```

Удаляются строки, у которых `used_at` или `expires_at` старше срока из `NOTIFICATIONS_TELEGRAM_LINK_TOKEN_RETENTION_DAYS`; действующие токены и история привязок не затрагиваются. Расписание запускает её тем же cron `NOTIFICATIONS_PURGE_CRON`.

## Демонстрация предпочтений и Telegram (M12)

Сценарий проходит в том же `docker compose` из каталога `courier/`; переходный период M11 удалён, поэтому переключать режимы нечего.

| Шаг | Действие | Что смотреть |
|---|---|---|
| 1 | `docker compose up -d` и событие заказа (`POST /api/v1/orders`, затем действия курьера) | письмо в Mailpit и строка журнала сервиса; `php artisan notifications:send --once --json` |
| 2 | Открыть `/account/notifications` под отправителем | матрица «6 событий × 3 канала», тихие часы и блок привязки; браузер обращается только к монолиту |
| 3 | Задать `NOTIFICATIONS_TELEGRAM_BOT_TOKEN` и `NOTIFICATIONS_TELEGRAM_BOT_USERNAME`, добавить `telegram` в `NOTIFICATIONS_ENABLED_CHANNELS`, выполнить `docker compose --profile telegram up -d notifications-telegram` | кнопка «Привязать» даёт deep-link, бот отвечает на `/start <token>`, статус становится `verified` |
| 4 | Включить Telegram у события и выключить push | строка журнала по каналу `telegram` в `sent`; при отвязке чата — `TELEGRAM_CHAT_NOT_FOUND` и строка следующего канала с `fallback_from_notification_id` |
| 5 | Включить тихие часы на текущее время для некритичного события | `deferred_until` заполнен, поллер строку не берёт, после окна она отправляется один раз |
| 6 | `php artisan notifications:telegram:purge --days=1` | удалены только использованные и истёкшие токены привязки |

Ограничения показа:

- реальный push-провайдер не обязателен: цепочка `push → telegram → email` проверяется фейком, которому задаётся детерминированный отказ (`NOTIFICATIONS_PUSH_FAKE_FAIL_RECIPIENTS`); реальный FCM включается `NOTIFICATIONS_PUSH_PROVIDER=fcm` и требует кредов;
- локального пути больше нет: «как было» до M12 показано артефактом [До и после - переходный период уведомлений (M11 → M12)](<../../.github/docs/До и после/До и после - переходный период уведомлений (M11 → M12).md>);
- токен бота и секрет вебхука задаются только окружением хоста и в репозиторий не попадают.

## Демонстрация сервиса (M13)

Пять минут показа из каталога `courier/`; подробная таблица «шаг — действие — результат — где смотреть — тест» — в [Демо-сценарий](../../docs/13-Демо-сценарий.md).

```sh
docker compose up -d notifications-app notifications-worker notifications-scheduler
docker compose exec -T notifications-app php artisan notifications:metrics-snapshot --refresh --json
# ... остановка и запуск сервиса, событие заказа ...
docker compose exec -T notifications-app php artisan notifications:dlq-list --state=open --json
docker compose exec -T notifications-app php artisan notifications:dlq-replay <id> --json
docker compose --profile broker up -d rabbitmq
docker compose exec -T notifications-app php artisan notifications:broker-health --json
```

Что показывает демо: исходный снимок метрик; остановка сервиса (заказы работают, Outbox и лаг растут); восстановление без дублей; терминальный отказ по каналу и запись DLQ; повтор записи и `resolved`; снимок «до/после»; переключение транспорта на RabbitMQ и обратно без потери событий. Переключения (попытки `NOTIFICATIONS_MAX_ATTEMPTS`, Mailpit, профиль `broker`) выполняются до начала показа.

## Проверки качества

```sh
# из каталога courier/
docker compose exec -T notifications-app composer test          # Pest
docker compose exec -T notifications-app composer lint          # Pint --test
docker compose exec -T notifications-app composer analyse       # PHPStan level 8
docker compose run --rm notifications-app sh docker/php/run-tests.sh  # прогон с проверкой risky
```

## Что проверяет каркас

- приложение поднимается и читает конфигурацию сервиса (`tests/Unit/ConfigTest.php`);
- сервисный токен по умолчанию не пуст, пути к контрактам и параметры
  транспорта читаются из конфигурации;
- корневой маршрут и `/up` отвечают без персональных данных и деталей окружения;
- `notifications:consume --once` на подставном потоке не создаёт дублей,
  подтверждает сообщение только после COMMIT и помечает недействительные
  сообщения (`tests/Feature/ConsumeCommandTest.php`);
- матрица каналов сверена с таблицей FR-NTF-242, тексты проверены на отсутствие
  персональных данных (`tests/Unit/NotificationPolicyTest.php`);
- поллер `notifications:send` отправляет готовые строки, пишет попытки,
  соблюдает backoff, исчерпывает попытки до `failed`, не повторяет
  бизнес-ошибку и не блокируется отравленной строкой
  (`tests/Feature/SendPollerTest.php`); расчёт backoff и разброса —
  `tests/Unit/BackoffScheduleTest.php`;
- API журнала, повтора и симулятора закрыт токеном, отдаёт
  `problem+json`, фильтры, курсорную пагинацию и не раскрывает персональные
  данные (`tests/Feature/ApiNotificationsTest.php`);
- таблицы сервиса держат уникальные индексы, CHECK статусов и структурные CHECK `jsonb`, частичный
  индекс Outbox и `timestamptz`, внешних ключей на монолит нет
  (`tests/Feature/SchemaGuardTest.php`);
- `/health` отвечает `200`/`503` при разных состояниях базы и Redis и не
  раскрывает персональных данных (`tests/Feature/HealthTest.php`);
- `notifications:check` проходит при корректной конфигурации и завершается
  кодом 1 при нечитаемом каталоге схем (`tests/Feature/CheckCommandTest.php`);
- предпочтения сохраняются набором и влияют на выбор каналов
  (`tests/Feature/PreferenceApiTest.php`, `OrderChannelSelectionTest.php`);
- привязка Telegram одноразовая, повторное подтверждение и истёкший токен
  отклоняются, `/start` без токена привязку не создаёт
  (`tests/Feature/TelegramBindingTest.php`, `TelegramUpdateTest.php`);
- замещение канала создаёт строку следующего канала, а тихие часы заполняют
  `deferred_until` (`tests/Feature/FallbackTest.php`, `QuietHoursDeferralTest.php`);
- DLQ пишется в одной транзакции с `failed`, повтор возвращает ту же строку,
  `resolved` фиксируется вместе с `sent`, очистка идёт по сроку
  (`tests/Feature/DlqTest.php`, `DlqApiTest.php`, `DlqConsoleTest.php`);
- `/metrics` закрыт токеном, отдаёт формат 0.0.4 и читает кэш без пересчёта базы;
  отключённый экспорт отвечает `404` (`tests/Feature/MetricsTest.php`,
  `tests/Unit/PrometheusTextExporterTest.php`);
- `fake` и `fcm` дают одинаковый эффект, `410 UNREGISTERED` отзывает токен,
  «нет токена» не создаёт DLQ, API токенов идемпотентен
  (`tests/Feature/PushProviderContractTest.php`, `FcmPushIntegrationTest.php`,
  `DeviceTokenApiTest.php`);
- неизвестный транспорт останавливает процесс, брокер отражается в `/health`,
  паритет Redis и RabbitMQ подтверждён одним набором
  (`tests/Feature/TransportGuardTest.php`, `BrokerHealthTest.php`, `TransportParityTest.php`).

## Ограничения этапа и границы M14

- push по умолчанию остаётся фейковым: `FakePushProvider` пишет в `push_messages`,
  поэтому канал считается доступным при включённом канале; реальный провайдер
  включается `NOTIFICATIONS_PUSH_PROVIDER=fcm` и требует кредов FCM;
- метрики отдаются по запросу: постоянного сборщика, дашбордов и алертинга в
  репозитории нет, контейнера Prometheus в `docker-compose.yml` тоже;
- лаг и pending считаются по consumer group Redis Streams даже при
  `NOTIFICATIONS_TRANSPORT=rabbitmq`; состояние брокера показывают `/health`
  и `notifications:broker-health`;
- устройство токенов и очистка: срок хранения отозванных `device_tokens` задан
  конфигурацией, но отдельной команды очистки нет; активные токены не чистятся;
- DLQ — рабочий список, а не второй источник истины: отдельного разбора DLX нет,
  сообщение из DLX возвращается в основной поток; запись DLQ удаляется только по
  своему сроку и не восстанавливается из журнала;
- один бот на окружение: имя и токен задаются окружением, ротация — перезапуском
  процесса бота; вебхук нужен только демонстрационному режиму, локально и в
  тестах используется long polling;
- тексты шаблонов живут только в сервисе; копий в монолите больше нет — они
  удалены вместе с переходным периодом;
- персональные данные приходят только потоком контактов (пункт 7): email
  хранится в `recipient_contacts`, `chat_id` — в `channel_bindings.address`;
  в логи, ответы API, события заказа и строки журнала они не попадают;
- предпочтения и привязки живут только в сервисе: при его недоступности экран
  настроек показывает предупреждение и не даёт менять настройки.
