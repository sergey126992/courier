<?php

declare(strict_types=1);

namespace App\Services\Notifications\Service;

use App\Domain\Notifications\NotificationChannel;
use App\Http\Middleware\RequestId;
use App\Support\CorrelationContext;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент служебного API сервиса оповещений (ЧТЗ 8.1, 8.2).
 *
 * Единственная точка вызова сервиса из монолита: журнал необработанных,
 * ручной повтор, журнал симулятора push, предпочтения получателя и привязки
 * каналов (FR-OPS-401…406). Сервисный токен передаётся заголовком
 * `Authorization`; ни токен, ни персональные данные не пишутся в лог —
 * только метод, путь и статус ответа (FR-OPS-206, FR-OPS-207).
 *
 * Таймаут, число повторов и пауза берутся из конфигурации: литералов в коде нет.
 */
final class NotificationServiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMilliseconds,
    ) {}

    /**
     * Журнал необработанных уведомлений сервиса.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function failed(int $limit, ?string $channel = null, ?string $cursor = null): array
    {
        $query = ['status' => 'failed', 'limit' => $limit];

        if ($channel !== null && $channel !== '') {
            $query['channel'] = $channel;
        }

        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        return $this->page('api/v1/notifications', $query);
    }

    /**
     * Очередь необработанных сервиса (FR-NTF-505, FR-NTF-512).
     *
     * Строки приходят без персональных данных: идентификаторы, событие,
     * канал, состояние разбора, класс и код причины, попытки и момент отказа.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function dlqEntries(int $limit, ?string $channel = null, ?string $state = null, ?string $cursor = null): array
    {
        $query = ['limit' => $limit];

        if ($channel !== null && $channel !== '') {
            $query['channel'] = $channel;
        }

        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        return $this->page('api/v1/dlq', $query);
    }

    /**
     * Повтор записи очереди необработанных (FR-NTF-506).
     *
     * `202` — повтор поставлен, `404` — записи нет, `409` — запись уже
     * в повторе или разобрана. Ответ `5xx` и отсутствие связи означают
     * недоступность сервиса, а не отказ по записи.
     *
     * @return NotificationServiceResult `data` — состояние записи после повтора
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function replayDlq(int $id): NotificationServiceResult
    {
        $response = $this->send('POST', sprintf('api/v1/dlq/%d/replay', $id));

        if ($response->failed()) {
            return $this->failure($response);
        }

        $payload = $this->assoc($response->json());
        $data = $this->assoc($payload['data'] ?? null);

        return NotificationServiceResult::ok([
            'id' => (int) ($data['id'] ?? $id),
            'notification_id' => (int) ($data['notification_id'] ?? 0),
            'state' => is_string($data['state'] ?? null) ? $data['state'] : '',
            'replay_count' => (int) ($data['replay_count'] ?? 0),
            'last_replayed_at' => is_string($data['last_replayed_at'] ?? null) ? $data['last_replayed_at'] : null,
        ], $response->status());
    }

    /**
     * Снимок метрик сервиса из `/metrics` (FR-OPS-511).
     *
     * Ответ приходит в формате Prometheus text 0.0.4; разбираются только
     * строки известных метрик с метками белого списка. Гистограмма
     * (`_bucket`, `_sum`, `_count`) на вкладке не показывается и пропускается.
     * Пустой ответ — пустой снимок, а не ошибка: сервис мог ещё ничего
     * не посчитать.
     *
     * @return array{
     *     counters: list<array{channel: string, metric: string, value: int}>,
     *     dlq_depth: list<array{channel: string, state: string, value: int}>,
     *     consumers: list<array{stream: string, lag_seconds: int, pending: int}>,
     *     outbox_oldest_unpublished_seconds: int|null
     * }
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function metrics(): array
    {
        if ($this->baseUrl === '' || $this->token === '') {
            throw NotificationServiceUnavailable::notConfigured();
        }

        $response = $this->call('GET', '/metrics', static fn (PendingRequest $request): Response => $request->get('/metrics'));

        if ($response->failed()) {
            Log::warning('notifications.service.unavailable', ['method' => 'GET', 'path' => '/metrics', 'status' => $response->status()]);

            throw NotificationServiceUnavailable::failed('GET', '/metrics', $response->status());
        }

        return $this->parseMetrics((string) $response->body());
    }

    /**
     * Журнал фейкового push-провайдера для страницы симулятора.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function pushMessages(int $limit): array
    {
        return $this->page('api/v1/push-messages', ['limit' => $limit]);
    }

    /**
     * Ручной повтор уведомления.
     *
     * Возвращает HTTP-статус сервиса: `202`, `404` или `409`. Ответы `5xx`
     * и отсутствие связи означают «сервис недоступен», а не отказ по записи.
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function retry(int $id): int
    {
        return $this->send('POST', sprintf('api/v1/notifications/%d/retry', $id))->status();
    }

    /**
     * Эффективные предпочтения получателя (FR-OPS-401, ЧТЗ 8.3).
     *
     * Значения приходят из сервиса: монолит их не хранит и не кэширует
     * (FR-OPS-405). Ответ читается по allowlist — типы событий, каналы,
     * источник строки, окно тихих часов и локаль (FR-OPS-406).
     *
     * `$role` сужает выдачу до набора типов событий роли: наборы задаёт
     * конфигурация сервиса, а не монолит (FR-NTF-460, FR-UI-408).
     *
     * @return NotificationServiceResult `data.rows` — список строк предпочтений
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function preferences(int $userId, ?string $role = null): NotificationServiceResult
    {
        $query = ['user_id' => $userId];

        if ($role !== null && $role !== '') {
            $query['role'] = $role;
        }

        $response = $this->send('GET', 'api/v1/preferences', [], $query);

        if ($response->failed()) {
            return $this->failure($response);
        }

        return NotificationServiceResult::ok(
            ['rows' => $this->preferenceRows($this->listOf($response->json(), 'preferences'))],
            $response->status(),
        );
    }

    /**
     * Сохранение набора строк предпочтений (FR-OPS-401, ЧТЗ 8.4).
     *
     * @param list<array{event_type: string, channels: list<string>, quiet_hours: array{from: string, to: string, timezone: string}|null}> $rows
     * @return NotificationServiceResult `data.rows` — новое состояние после сохранения
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function savePreferences(int $userId, array $rows, ?string $role = null): NotificationServiceResult
    {
        $payload = [
            'user_id' => $userId,
            'preferences' => $rows,
        ];

        if ($role !== null && $role !== '') {
            $payload['role'] = $role;
        }

        $response = $this->send('PATCH', 'api/v1/preferences', $payload);

        if ($response->failed()) {
            return $this->failure($response);
        }

        return NotificationServiceResult::ok(
            ['rows' => $this->preferenceRows($this->listOf($response->json(), 'preferences'))],
            $response->status(),
        );
    }

    /**
     * Выпуск одноразовой ссылки привязки Telegram (FR-OPS-401, ЧТЗ 8.1).
     *
     * @return NotificationServiceResult `data` — `deep_link`, `expires_at`, `status`
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function startTelegramBinding(int $userId): NotificationServiceResult
    {
        $response = $this->send('POST', 'api/v1/channel-bindings/telegram/start', ['user_id' => $userId]);

        if ($response->failed()) {
            return $this->failure($response);
        }

        $payload = $this->assoc($response->json());

        return NotificationServiceResult::ok([
            'deep_link' => is_string($payload['deep_link'] ?? null) ? $payload['deep_link'] : '',
            'expires_at' => is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : '',
            'status' => is_string($payload['status'] ?? null) ? $payload['status'] : 'pending',
        ], $response->status());
    }

    /**
     * Отвязка Telegram (FR-OPS-401, ЧТЗ 8.1).
     *
     * @return NotificationServiceResult `409 TELEGRAM_BINDING_ABSENT` — привязки нет
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function unbindTelegram(int $userId): NotificationServiceResult
    {
        $response = $this->send('DELETE', 'api/v1/channel-bindings/telegram', ['user_id' => $userId]);

        if ($response->failed()) {
            return $this->failure($response);
        }

        return NotificationServiceResult::ok([], $response->status());
    }

    /**
     * Статус привязки Telegram без адресов и токенов (FR-OPS-401, FR-OPS-406).
     *
     * @return NotificationServiceResult `data.binding` — status и verified_at или null
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function channelBindings(int $userId): NotificationServiceResult
    {
        $response = $this->send('GET', 'api/v1/channel-bindings', [], ['user_id' => $userId]);

        if ($response->failed()) {
            return $this->failure($response);
        }

        return NotificationServiceResult::ok(
            ['binding' => $this->telegramBinding($this->listOf($response->json(), 'bindings'))],
            $response->status(),
        );
    }

    /**
     * Запрос к сервису с общей обработкой отказа (FR-OPS-403).
     *
     * `5xx` и отсутствие связи означают недоступность сервиса: вызывающая
     * страница блокирует изменения и показывает предупреждение. Остальные
     * отказы возвращаются как доменный результат с кодом причины.
     *
     * @param array<string, mixed> $payload
     * @param array<string, int|string> $query
     *
     * @throws NotificationServiceUnavailable сервис не настроен или не ответил
     */
    private function send(string $method, string $path, array $payload = [], array $query = []): Response
    {
        $response = $this->call($method, $path, static fn (PendingRequest $request): Response => match ($method) {
            'GET' => $request->get($path, $query),
            'PATCH' => $request->patch($path, $payload),
            'POST' => $request->post($path, $payload),
            default => $request->delete($path, $payload),
        });

        if ($response->serverError() || $response->status() === 0) {
            Log::warning('notifications.service.unavailable', ['method' => $method, 'path' => $path, 'status' => $response->status()]);

            throw NotificationServiceUnavailable::failed($method, $path, $response->status());
        }

        return $response;
    }

    /**
     * Отказ сервиса в доменный результат: только код, без текста исключения.
     */
    private function failure(Response $response): NotificationServiceResult
    {
        $payload = $this->assoc($response->json());
        $code = $payload['code'] ?? null;

        return NotificationServiceResult::failed($response->status(), is_string($code) ? $code : null);
    }

    /**
     * Строки предпочтений по allowlist ответа (FR-OPS-406).
     *
     * @param array<mixed> $rows
     * @return list<array{event_type: string, channels: list<string>, source: string, quiet_hours: array{from: string, to: string, timezone: string}|null, locale: string}>
     */
    private function preferenceRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->preferenceRow($row);
            }
        }

        return $result;
    }

    /**
     * Строка предпочтений по allowlist: `chat_id` и адреса не сохраняются.
     *
     * @param array<mixed> $row
     * @return array{event_type: string, channels: list<string>, source: string, quiet_hours: array{from: string, to: string, timezone: string}|null, locale: string}
     */
    private function preferenceRow(array $row): array
    {
        $channels = [];
        $source = $row['channels'] ?? null;

        foreach (is_array($source) ? $source : [] as $channel) {
            if (is_string($channel) && $channel !== '') {
                $channels[] = $channel;
            }
        }

        $quietHours = null;

        if (is_array($row['quiet_hours'] ?? null)) {
            /** @var array<mixed> $quiet */
            $quiet = $row['quiet_hours'];

            $from = $quiet['from'] ?? null;
            $to = $quiet['to'] ?? null;
            $timezone = $quiet['timezone'] ?? null;

            if (is_string($from) && is_string($to) && is_string($timezone)) {
                $quietHours = ['from' => $from, 'to' => $to, 'timezone' => $timezone];
            }
        }

        return [
            'event_type' => is_string($row['event_type'] ?? null) ? $row['event_type'] : '',
            'channels' => $channels,
            'source' => is_string($row['source'] ?? null) ? $row['source'] : '',
            'quiet_hours' => $quietHours,
            'locale' => is_string($row['locale'] ?? null) ? $row['locale'] : '',
        ];
    }

    /**
     * Привязка Telegram по allowlist: канал, статус и момент подтверждения.
     *
     * @param list<array<mixed>> $bindings
     * @return array{status: string, verified_at: string|null}|null null — привязки нет
     */
    private function telegramBinding(array $bindings): ?array
    {
        foreach ($bindings as $binding) {
            if (($binding['channel'] ?? null) !== NotificationChannel::Telegram->value) {
                continue;
            }

            return [
                'status' => is_string($binding['status'] ?? null) ? $binding['status'] : 'absent',
                'verified_at' => is_string($binding['verified_at'] ?? null) ? $binding['verified_at'] : null,
            ];
        }

        return null;
    }

    /**
     * Список вложенных объектов ответа по ключу конверта.
     *
     * @return list<array<mixed>>
     */
    private function listOf(mixed $payload, string $key): array
    {
        $items = is_array($payload) ? ($payload[$key] ?? null) : null;
        $rows = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * Разбор текста Prometheus 0.0.4 в снимок вкладки «Метрики» (FR-OPS-511).
     *
     * Строки комментариев (`# HELP`, `# TYPE`) и серии гистограммы пропускаются.
     * Метки читаются только ожидаемые — идентификаторы и адреса в снимок
     * вкладки не попадают (FR-OPS-512).
     *
     * @return array{
     *     counters: list<array{channel: string, metric: string, value: int}>,
     *     dlq_depth: list<array{channel: string, state: string, value: int}>,
     *     consumers: list<array{stream: string, lag_seconds: int, pending: int}>,
     *     outbox_oldest_unpublished_seconds: int|null
     * }
     */
    private function parseMetrics(string $body): array
    {
        $metrics = [
            'notifications_sent_total' => 'sent',
            'notifications_failed_total' => 'failed',
            'notifications_retried_total' => 'retried',
        ];

        $counters = [];
        $dlq = [];
        $lag = [];
        $pending = [];
        $outbox = null;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            $matched = preg_match(
                '/^([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{([^}]*)\})?\s+(\S+)$/',
                $line,
                $matches,
                PREG_UNMATCHED_AS_NULL,
            );

            if ($line === '' || str_starts_with($line, '#') || $matched !== 1) {
                continue;
            }

            $name = (string) $matches[1];
            $labels = $this->metricLabels((string) ($matches[2] ?? ''));
            $value = $matches[3];

            if (isset($metrics[$name])) {
                $counters[] = [
                    'channel' => (string) ($labels['channel'] ?? ''),
                    'metric' => $metrics[$name],
                    'value' => $this->metricInt($value),
                ];

                continue;
            }

            if ($name === 'notifications_dlq_depth') {
                $dlq[] = [
                    'channel' => (string) ($labels['channel'] ?? ''),
                    'state' => (string) ($labels['state'] ?? ''),
                    'value' => $this->metricInt($value),
                ];

                continue;
            }

            if ($name === 'notifications_consumer_lag_seconds') {
                $lag[(string) ($labels['stream'] ?? '')] = $this->metricInt($value);

                continue;
            }

            if ($name === 'notifications_consumer_pending') {
                $pending[(string) ($labels['stream'] ?? '')] = $this->metricInt($value);

                continue;
            }

            if ($name === 'notifications_outbox_oldest_unpublished_seconds') {
                $outbox = $this->metricInt($value);
            }
        }

        $consumers = [];

        foreach ($lag + $pending as $stream => $unused) {
            $consumers[] = [
                'stream' => (string) $stream,
                'lag_seconds' => $lag[$stream] ?? 0,
                'pending' => $pending[$stream] ?? 0,
            ];
        }

        return [
            'counters' => $counters,
            'dlq_depth' => $dlq,
            'consumers' => $consumers,
            'outbox_oldest_unpublished_seconds' => $outbox,
        ];
    }

    /**
     * Метки серии: ожидаемые имена и раскодированные значения.
     *
     * @return array<string, string>
     */
    private function metricLabels(string $text): array
    {
        $labels = [];

        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $text, $matches, PREG_SET_ORDER) === false) {
            return $labels;
        }

        foreach ($matches as $match) {
            $labels[$match[1]] = str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], $match[2]);
        }

        return $labels;
    }

    /**
     * Целое значение серии; `+Inf` и мусор дают ноль.
     */
    private function metricInt(string $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Объект ответа без обещаний о содержимом.
     *
     * @return array<mixed>
     */
    private function assoc(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    /**
     * Постраничная выдача сервиса: конверт `{"data": [...], "next_cursor": ...}`.
     *
     * @param array<string, int|string> $query
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    private function page(string $path, array $query): array
    {
        $response = $this->call('GET', $path, static fn (PendingRequest $request): Response => $request->get($path, $query));

        if ($response->failed()) {
            Log::warning('notifications.service.unavailable', ['method' => 'GET', 'path' => $path, 'status' => $response->status()]);

            throw NotificationServiceUnavailable::failed('GET', $path, $response->status());
        }

        $payload = $response->json();
        $rows = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $cursor = is_array($payload) ? ($payload['next_cursor'] ?? null) : null;

        return [
            'rows' => array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row))),
            'next_cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null,
        ];
    }

    /**
     * Запрос с таймаутом и повтором из конфигурации.
     *
     * @param Closure(PendingRequest): Response $callback
     *
     * @throws NotificationServiceUnavailable сервис не настроен или не ответил
     */
    private function call(string $method, string $path, Closure $callback): Response
    {
        if ($this->baseUrl === '' || $this->token === '') {
            throw NotificationServiceUnavailable::notConfigured();
        }

        try {
            return $callback($this->client());
        } catch (ConnectionException $exception) {
            Log::warning('notifications.service.unavailable', ['method' => $method, 'path' => $path, 'status' => null]);

            throw NotificationServiceUnavailable::failed($method, $path, null, $exception);
        }
    }

    /**
     * Клиент служебного API: JSON, токен, таймаут, повтор и сквозной
     * идентификатор запроса. Заголовок `X-Request-Id` передаёт correlation_id
     * исходного запроса администратора: записи сервиса связываются с ним
     * (FR-OPS-201). Вне HTTP создаётся новый UUID.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->token)
            ->withHeaders([RequestId::HEADER => CorrelationContext::resolve()])
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->retry($this->retryTimes, $this->retrySleepMilliseconds, throw: false);
    }
}
