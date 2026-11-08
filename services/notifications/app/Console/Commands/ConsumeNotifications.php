<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Events\ConsumeResult;
use App\Services\Events\ContactEventConsumer;
use App\Services\Events\Contracts\EventStream;
use App\Services\Events\DeadLetterRedriver;
use App\Services\Events\OrderEventConsumer;
use Illuminate\Console\Command;

/**
 * Потребитель потоков сервиса: заказы и контакты (FR-NTF-204, FR-NTF-224, FR-NTF-265).
 *
 * Один проход читает пачку через consumer group сервиса, проверяет конверт
 * по общей JSON Schema, выполняет эффект и подтверждает сообщение только
 * после COMMIT. Возврат «зависших» сообщений и подтверждение — часть
 * транспорта; команда отвечает за ключи запуска, коды возврата и вывод.
 *
 * Поток контактов обслуживается тем же процессом, но своим получателем
 * в группе (FR-NTF-231): у долгоживущего процесса каждый цикл читает
 * сначала заказы, затем контакты. Ключ `--once` обрабатывает один поток:
 * по умолчанию поток заказов, поток контактов — `--stream=contacts`.
 *
 * Ключи: `--once` — один проход и завершение; `--limit` — размер пачки;
 * `--stream` — имя потока (по умолчанию поток заказов); `--json` — отчёт
 * в JSON. Коды возврата: 0 — пачка обработана; 1 — есть ошибки обработки;
 * 2 — ошибка конфигурации. В выводе нет персональных данных и тел событий
 * (FR-OPS-202).
 */
final class ConsumeNotifications extends Command
{
    /**
     * Пачка обработана.
     */
    public const EXIT_OK = 0;

    /**
     * Есть ошибки обработки: сообщения остались в pending.
     */
    public const EXIT_FAILED = 1;

    /**
     * Ошибка конфигурации потребителя.
     */
    public const EXIT_INVALID_CONFIGURATION = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:consume
        {--once : Обработать одну пачку и завершиться}
        {--limit= : Размер пачки сообщений}
        {--stream= : Имя потока (по умолчанию поток заказов)}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Потребитель потоков событий заказов и контактов';

    /**
     * Признак запроса остановки процесса (SIGTERM/SIGINT).
     */
    private bool $stopping = false;

    public function handle(OrderEventConsumer $consumer, ContactEventConsumer $contacts, EventStream $stream): int
    {
        $limit = $this->limit();
        $once = (bool) $this->option('once');
        $redriver = $stream instanceof DeadLetterRedriver ? $stream : null;
        $targets = $this->targets($once);
        $explicit = trim((string) $this->option('stream'));
        $redriveOnly = $redriver !== null
            && $explicit !== ''
            && $explicit === trim((string) config('notifications.transport.rabbitmq.dlq_queue'));

        if ($limit < 1 || ($targets === [] && ! $redriveOnly)) {
            $this->error($this->getName().': некорректная конфигурация потребителя');

            return self::EXIT_INVALID_CONFIGURATION;
        }

        // Брокер опционален: без профиля `broker` возврат DLX не выполняется
        // и поведение потребителя не меняется (FR-NTF-546).
        if ($redriveOnly && $redriver !== null) {
            $redriven = $redriver->redriveDeadLetters($limit);
            $this->renderRedrive($redriver->deadLetterQueue(), $redriven);

            return $redriven === null ? self::EXIT_FAILED : self::EXIT_OK;
        }

        if ($once) {
            $failed = false;

            foreach ($targets as $target) {
                $result = $this->pass($consumer, $contacts, $target['stream'], $target['consumer'], $limit);
                $this->render($target['stream'], $result);
                $failed = $failed || $result->hasFailures();
            }

            return $failed ? self::EXIT_FAILED : self::EXIT_OK;
        }

        $this->installStopHandlers();

        $sleep = max(1, (int) config('notifications.consumer.sleep_seconds'));

        while (! $this->stopping) {
            foreach ($targets as $target) {
                $result = $this->pass($consumer, $contacts, $target['stream'], $target['consumer'], $limit);

                if (! $result->isEmpty()) {
                    $this->render($target['stream'], $result);
                }

                if ($result->isEmpty() || $result->degraded) {
                    // Пауза нужна и при недоступном транспорте: иначе цикл
                    // превратится в непрерывные попытки подключения к Redis.
                    sleep($sleep);
                }
            }

            // Сообщения DLX возвращаются в основной поток и обрабатываются
            // обычными правилами (FR-NTF-510, FR-NTF-544).
            if ($redriver !== null && $explicit === '') {
                $redriven = $redriver->redriveDeadLetters($limit);

                if ($redriven !== null && $redriven > 0) {
                    $this->renderRedrive($redriver->deadLetterQueue(), $redriven);
                }
            }
        }

        return self::EXIT_OK;
    }

    /**
     * Потоки одного запуска: явный `--stream` или набор по режиму работы.
     *
     * @return list<array{stream: string, consumer: string}>
     */
    private function targets(bool $once): array
    {
        $orders = trim((string) config('notifications.transport.streams.orders'));
        $contacts = trim((string) config('notifications.transport.streams.contacts'));
        $ordersConsumer = trim((string) config('notifications.transport.consumer_name'));
        $contactsConsumer = trim((string) config('notifications.transport.contacts_consumer_name'));
        $explicit = trim((string) $this->option('stream'));

        if ($orders === '' || $contacts === '' || $ordersConsumer === '' || $contactsConsumer === '') {
            return [];
        }

        if ($explicit !== '') {
            return match ($explicit) {
                $orders => [['stream' => $orders, 'consumer' => $ordersConsumer]],
                $contacts => [['stream' => $contacts, 'consumer' => $contactsConsumer]],
                // Явный DLQ-поток обрабатывается возвратом сообщений (FR-NTF-510).
                trim((string) config('notifications.transport.rabbitmq.dlq_queue')) => [],
                default => [],
            };
        }

        $targets = [['stream' => $orders, 'consumer' => $ordersConsumer]];

        if (! $once) {
            $targets[] = ['stream' => $contacts, 'consumer' => $contactsConsumer];
        }

        return $targets;
    }

    /**
     * Вывод возврата сообщений DLQ: только счётчик, без тел событий.
     */
    private function renderRedrive(string $queue, ?int $redriven): void
    {
        if ($redriven === null) {
            $this->error($this->getName().': DLQ недоступна');

            return;
        }

        $this->info(sprintf('%s: из DLQ возвращено %d сообщений', $this->getName(), $redriven));
    }

    /**
     * Один проход по потоку: получатель выбирается по имени потока.
     */
    private function pass(
        OrderEventConsumer $orders,
        ContactEventConsumer $contacts,
        string $stream,
        string $consumerName,
        int $limit,
    ): ConsumeResult {
        if ($stream === trim((string) config('notifications.transport.streams.contacts'))) {
            return $contacts->consume($stream, $consumerName, $limit);
        }

        return $orders->consume($stream, $consumerName, $limit);
    }

    /**
     * Размер пачки: ключ `--limit`, иначе значение из конфигурации.
     */
    private function limit(): int
    {
        $option = $this->option('limit');

        return $option === null || $option === '' || ! is_numeric($option)
            ? (int) config('notifications.transport.batch_size')
            : (int) $option;
    }

    /**
     * Останавливается по SIGTERM/SIGINT, если расширение pcntl доступно.
     *
     * Без pcntl контейнер завершает процесс обычным способом: сообщение,
     * полученное но не подтверждённое, остаётся в pending и будет выдано
     * повторно после `pending_idle_seconds` (FR-NTF-265).
     */
    private function installStopHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach (['SIGTERM', 'SIGINT'] as $name) {
            if (! defined($name)) {
                continue;
            }

            /** @var int $signal */
            $signal = constant($name);
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
    }

    /**
     * Вывод одного прохода: только счётчики и имя потока.
     */
    private function render(string $stream, ConsumeResult $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'command' => $this->getName(),
                'status' => $result->hasFailures() ? 'error' : 'ok',
                'stream' => $stream,
                'read' => $result->read,
                'processed' => $result->processed,
                'acknowledged' => $result->acknowledged,
                'failed' => $result->failed,
                'invalid' => $result->invalid,
                'created' => $result->created,
                'degraded' => $result->degraded,
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info(sprintf(
            '%s: прочитано %d, обработано %d, подтверждено %d, создано %d, ошибок %d, недействительных %d%s',
            $this->getName(),
            $result->read,
            $result->processed,
            $result->acknowledged,
            $result->created,
            $result->failed,
            $result->invalid,
            $result->degraded ? ', транспорт недоступен' : '',
        ));
    }
}
