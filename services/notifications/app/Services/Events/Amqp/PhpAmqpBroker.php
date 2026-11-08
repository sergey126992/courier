<?php

declare(strict_types=1);

namespace App\Services\Events\Amqp;

use App\Domain\Notifications\NotificationErrorCode;
use Closure;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Клиент RabbitMQ поверх `php-amqplib/php-amqplib` (FR-NTF-541…552).
 *
 * Библиотека выбрана потому, что это чистый PHP без расширения `ext-amqp`
 * и с явным контролем подтверждений: `basic_ack` вызывается отдельно,
 * а `basic_reject(requeue: false)` отправляет сообщение в DLX. Соединение
 * открывается лениво; любая инфраструктурная ошибка не пробрасывается
 * наружу, а превращается в `false`/`null` — вызывающий код сообщает
 * `degraded` (FR-NTF-548). Параметры подключения приходят из конфигурации,
 * пароль в журнал не попадает: записывается только код причины (FR-NTF-549).
 */
final class PhpAmqpBroker implements AmqpBroker
{
    /**
     * Код журнала при неудачном подтверждении.
     */
    private const ACK_FAILED_CODE = 'EVENT_ACK_FAILED';

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /**
     * Проставляет теги доставки по идентификаторам сообщений.
     *
     * @var array<int|string, int>
     */
    private array $deliveryTags = [];

    /**
     * Признак возврата сообщения брокером (basic.return).
     *
     * Состояние в массиве: слушатель возврата пишет его изнутри callable,
     * и скалярное свойство анализатор считал бы неизменным.
     *
     * @var array{returned: bool}
     */
    private array $publishState = ['returned' => false];

    /**
     * @param array{host: string, port: int, user: string, password: string, vhost: string,
     *              heartbeat: int, timeout: int, prefetch: int} $config параметры подключения
     * @param (Closure(): ?AMQPChannel)|null $channelFactory подмена канала для проверок без сети
     */
    public function __construct(
        private readonly array $config,
        private readonly ?Closure $channelFactory = null,
    ) {}

    public function ensureExchange(string $exchange, string $type = 'topic'): bool
    {
        return $this->run(static function (AMQPChannel $channel) use ($exchange, $type): void {
            $channel->exchange_declare($exchange, $type, false, true, false);
        });
    }

    public function ensureQueue(string $queue, array $arguments = []): bool
    {
        return $this->run(static function (AMQPChannel $channel) use ($queue, $arguments): void {
            $table = $arguments === [] ? [] : new AMQPTable($arguments);
            $channel->queue_declare($queue, false, true, false, false, false, $table);
        });
    }

    public function bind(string $exchange, string $queue, string $pattern): bool
    {
        return $this->run(static function (AMQPChannel $channel) use ($exchange, $queue, $pattern): void {
            $channel->queue_bind($queue, $exchange, $pattern);
        });
    }

    public function publish(string $exchange, string $routingKey, array $fields, int $expirationMs, array $headers): bool
    {
        $channel = $this->channel();

        if ($channel === null) {
            return false;
        }

        $this->publishState['returned'] = false;

        try {
            $properties = [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                // Per-message TTL: истёкшее сообщение уходит в DLX (FR-NTF-544).
                'expiration' => (string) $expirationMs,
            ];

            if ($headers !== []) {
                $properties['application_headers'] = new AMQPTable($headers);
            }

            $message = new AMQPMessage(
                (string) json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $properties,
            );

            $channel->basic_publish($message, $exchange, $routingKey, true);
            // Ждём подтверждения публикации и возврата необработанного
            // сообщения: без привязки брокер вернёт его, и публикация
            // считается неуспешной (FR-NTF-550).
            $channel->wait_for_pending_acks_returns((float) $this->config['timeout']);

            return ! $this->publishReturned();
        } catch (Throwable $exception) {
            $this->logUnavailable('publish', $exception);
            $this->close();

            return false;
        }
    }

    public function read(string $queue, int $limit): ?array
    {
        if ($limit < 1) {
            return [];
        }

        $channel = $this->channel();

        if ($channel === null) {
            return null;
        }

        try {
            $messages = [];

            for ($index = 0; $index < $limit; $index++) {
                $message = $channel->basic_get($queue, false);

                if (! $message instanceof AMQPMessage) {
                    break;
                }

                $id = (string) $message->getDeliveryTag();
                $this->deliveryTags[$id] = (int) $message->getDeliveryTag();

                $messages[] = new BrokerMessage(
                    id: $id,
                    fields: $this->fields($message),
                    headers: $this->headers($message),
                    routingKey: (string) $message->getRoutingKey(),
                );
            }

            return $messages;
        } catch (Throwable $exception) {
            $this->logUnavailable('basic_get', $exception);

            return null;
        }
    }

    public function ack(string $messageId): bool
    {
        $tag = $this->deliveryTags[$messageId] ?? null;
        $channel = $this->channel();

        if ($tag === null || $channel === null) {
            return false;
        }

        try {
            $channel->basic_ack($tag);
            unset($this->deliveryTags[$messageId]);

            return true;
        } catch (Throwable) {
            Log::error('Сообщение не подтверждено в брокере.', [
                'error_code' => self::ACK_FAILED_CODE,
            ]);

            return false;
        }
    }

    public function reject(string $messageId, bool $requeue): bool
    {
        $tag = $this->deliveryTags[$messageId] ?? null;
        $channel = $this->channel();

        if ($tag === null || $channel === null) {
            return false;
        }

        try {
            $channel->basic_reject($tag, $requeue);
            unset($this->deliveryTags[$messageId]);

            return true;
        } catch (Throwable $exception) {
            $this->logUnavailable('basic_reject', $exception);

            return false;
        }
    }

    public function depth(string $queue): ?int
    {
        $channel = $this->channel();

        if ($channel === null) {
            return null;
        }

        try {
            $result = $channel->queue_declare($queue, true, true, false, false);

            if (! is_array($result) || ! isset($result[1]) || ! is_numeric($result[1])) {
                return null;
            }

            return (int) $result[1];
        } catch (Throwable $exception) {
            $this->logUnavailable('queue_declare', $exception);

            return null;
        }
    }

    public function isConnected(): bool
    {
        return $this->channel() !== null;
    }

    public function close(): void
    {
        try {
            if ($this->channel !== null) {
                $this->channel->close();
            }

            if ($this->connection !== null) {
                $this->connection->close();
            }
        } catch (Throwable) {
            // Закрытие не влияет на результат прохода.
        }

        $this->channel = null;
        $this->connection = null;
        $this->deliveryTags = [];
    }

    /**
     * Сообщение вернулось от брокера как необработанное (FR-NTF-550).
     */
    private function publishReturned(): bool
    {
        return $this->publishState['returned'];
    }

    /**
     * Соединение с брокером; `null` — брокер недоступен.
     */
    private function channel(): ?AMQPChannel
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        if ($this->channelFactory !== null) {
            try {
                $this->channel = ($this->channelFactory)();

                return $this->channel;
            } catch (Throwable $exception) {
                $this->logUnavailable('connect', $exception);

                return null;
            }
        }

        try {
            $this->connection = new AMQPStreamConnection(
                host: $this->config['host'],
                port: $this->config['port'],
                user: $this->config['user'],
                password: $this->config['password'],
                vhost: $this->config['vhost'],
                connection_timeout: (float) $this->config['timeout'],
                read_write_timeout: (float) $this->config['timeout'],
                heartbeat: $this->config['heartbeat'],
            );

            $channel = new AMQPChannel($this->connection);
            // Prefetch из конфигурации: брокер не выдаёт потребителю больше,
            // чем тот способен подтвердить (FR-NTF-543).
            $channel->basic_qos(0, $this->config['prefetch'], false);
            // Подтверждения публикации и возврат необработанного сообщения:
            // удалённая очередь не означает потерю события (FR-NTF-550).
            $channel->confirm_select();
            $channel->set_return_listener(function (): void {
                $this->publishState['returned'] = true;
            });

            $this->channel = $channel;

            return $channel;
        } catch (Throwable $exception) {
            $this->logUnavailable('connect', $exception);

            return null;
        }
    }

    /**
     * Выполняет операцию с каналом; ошибка не пробрасывается наружу.
     *
     * @param callable(AMQPChannel): void $operation
     */
    private function run(callable $operation): bool
    {
        $channel = $this->channel();

        if ($channel === null) {
            return false;
        }

        try {
            $operation($channel);

            return true;
        } catch (Throwable $exception) {
            $this->logUnavailable('channel', $exception);
            $this->close();

            return false;
        }
    }

    /**
     * Поля конверта из тела сообщения.
     *
     * @return array<string, string>
     */
    private function fields(AMQPMessage $message): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                return [];
            }

            $fields = [];

            foreach ($decoded as $name => $value) {
                $fields[(string) $name] = is_scalar($value) ? (string) $value : '';
            }

            return $fields;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Служебные заголовки сообщения (счётчик попыток).
     *
     * @return array<string, mixed>
     */
    private function headers(AMQPMessage $message): array
    {
        try {
            $table = $message->get('application_headers');

            if ($table instanceof AMQPTable) {
                return $table->getNativeData();
            }
        } catch (Throwable) {
            // Отсутствие заголовков равнозначно первой попытке.
        }

        return [];
    }

    /**
     * Журнал недоступности брокера: только операция и код причины.
     *
     * Текст исключения клиента не пишется: он может содержать адрес
     * и параметры подключения (FR-NTF-549).
     */
    private function logUnavailable(string $operation, ?Throwable $exception = null): void
    {
        Log::warning('Брокер событий недоступен; операция пропущена.', [
            'error_code' => NotificationErrorCode::TRANSPORT_UNAVAILABLE,
            'operation' => $operation,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
