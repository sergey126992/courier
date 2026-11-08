<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Events\EventEnvelope;
use App\Services\Events\ConsumeResult;
use App\Services\Events\Contracts\EventBus;
use App\Services\Notifications\NotificationStatusProjector;
use Illuminate\Console\Command;

/**
 * Потребитель обратных событий сервиса оповещений (FR-NTF-207, FR-NTF-284).
 *
 * Читает поток `events.notifications` consumer group монолита (`courier` из
 * конфигурации) через контракт `EventBus`: транспорт и способ доставки от
 * команды не зависят (FR-NTF-201). Эффект — строка проекции
 * `order_notification_status`; подтверждение выполняется транспортом только
 * после фиксации эффекта, повтор события не создаёт вторую строку.
 *
 * Ключи: `--once` — один проход и завершение (для расписания и проверок),
 * `--limit` — размер пачки, `--json` — машиночитаемый вывод.
 * Коды возврата: 0 — пачка обработана (в том числе пустая); 1 — есть ошибки
 * обработки, сообщения остались в pending; 2 — ошибка конфигурации.
 * В выводе только счётчики: персональных данных нет (FR-OPS-202).
 */
final class ConsumeNotificationStatus extends Command
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
    protected $signature = 'notifications:consume-status
        {--once : Обработать одну пачку и завершиться}
        {--limit= : Размер пачки сообщений}
        {--json : Вывести результат в JSON}';

    /**
     * @var string
     */
    protected $description = 'Потребитель обратных событий о доставке уведомлений';

    /**
     * Признак запроса остановки процесса (SIGTERM/SIGINT).
     */
    private bool $stopping = false;

    public function handle(EventBus $bus, NotificationStatusProjector $projector): int
    {
        $stream = trim((string) config('events.streams.notifications'));
        $group = trim((string) config('events.status_consumer_group'));
        $consumer = trim((string) config('events.status_consumer_name'));
        $limit = $this->limit();

        if ($stream === '' || $group === '' || $consumer === '' || $limit === null) {
            $this->error($this->getName().': некорректная конфигурация потребителя');

            return self::EXIT_INVALID_CONFIGURATION;
        }

        if ((bool) $this->option('once')) {
            $result = $this->pass($bus, $projector, $stream, $group, $consumer, $limit);
            $this->render($result);

            return $result->hasFailures() ? self::EXIT_FAILED : self::EXIT_OK;
        }

        $sleep = max(1, (int) config('events.status_consume_interval_seconds'));

        $this->installStopHandlers();

        while (! $this->stopping) {
            $result = $this->pass($bus, $projector, $stream, $group, $consumer, $limit);

            if ($result->read > 0 || $result->degraded) {
                $this->render($result);
            }

            // Пауза нужна и при недоступном транспорте: иначе цикл превратится
            // в непрерывные попытки подключения к Redis.
            sleep($sleep);
        }

        return self::EXIT_OK;
    }

    /**
     * Останавливается по SIGTERM/SIGINT, если расширение pcntl доступно.
     *
     * Без pcntl проход конечен: команда используется в расписании с `--once`,
     * а неподтверждённое сообщение будет выдано повторно транспортом.
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
     * Один проход по потоку обратных событий.
     */
    private function pass(
        EventBus $bus,
        NotificationStatusProjector $projector,
        string $stream,
        string $group,
        string $consumer,
        int $limit,
    ): ConsumeResult {
        return $bus->consume(
            $stream,
            $group,
            $consumer,
            $limit,
            static function (EventEnvelope $event) use ($projector): void {
                // Неизвестный тип или версия уже подтверждаются транспортом;
                // событие не своей проекции тоже не должно оставлять pending.
                $projector->project($event);
            },
        );
    }

    /**
     * Размер пачки: ключ `--limit`, иначе значение из конфигурации.
     */
    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null || $option === '') {
            $limit = (int) config('events.status_consume_batch_size');

            return $limit > 0 ? $limit : null;
        }

        return is_string($option) && ctype_digit($option) && (int) $option > 0
            ? (int) $option
            : null;
    }

    /**
     * Вывод одного прохода: только счётчики и имя потока.
     */
    private function render(ConsumeResult $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'command' => $this->getName(),
                'status' => $result->hasFailures() ? 'error' : 'ok',
                'read' => $result->read,
                'processed' => $result->processed,
                'acknowledged' => $result->acknowledged,
                'failed' => $result->failed,
                'invalid' => $result->invalid,
                'degraded' => $result->degraded,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info(sprintf(
            '%s: прочитано %d, обработано %d, подтверждено %d, ошибок %d, недействительных %d%s',
            $this->getName(),
            $result->read,
            $result->processed,
            $result->acknowledged,
            $result->failed,
            $result->invalid,
            $result->degraded ? ' (транспорт недоступен)' : '',
        ));
    }
}
