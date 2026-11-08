<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\OutboxPublisher;
use App\Services\Notifications\OutboxPublishResult;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Один проход публикатора обратных событий сервиса (FR-NTF-283, ЧТЗ 8.7).
 *
 * Коды возврата: 0 — проход выполнен, в том числе пустой и при занятой
 * блокировке; 1 — есть неудачные публикации; 2 — ошибка конфигурации или
 * исключение. В выводе только счётчики: персональных данных нет.
 */
final class PublishNotificationOutbox extends Command
{
    /**
     * Проход выполнен (в том числе пустой и при занятой блокировке).
     */
    public const EXIT_OK = 0;

    /**
     * Есть неудачные публикации.
     */
    public const EXIT_FAILED = 1;

    /**
     * Ошибка конфигурации или исключение.
     */
    public const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:publish-outbox
        {--limit= : Максимальное число событий за проход}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Публикует пачку обратных событий Outbox сервиса';

    public function handle(OutboxPublisher $publisher): int
    {
        try {
            $result = $publisher->run($this->limit());
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        } catch (Throwable) {
            return $this->reportError('Проход публикатора завершился ошибкой.');
        }

        $this->render($result);

        return $result->hasFailures() ? self::EXIT_FAILED : self::EXIT_OK;
    }

    /**
     * Ограничение пачки из флага: пустое значение — размер из конфигурации.
     *
     * @throws InvalidArgumentException значение не является положительным числом
     */
    private function limit(): ?int
    {
        $value = $this->option('limit');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Флаг --limit должен быть положительным целым числом.');
        }

        return (int) $value;
    }

    private function render(OutboxPublishResult $result): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'published' => $result->published,
                'failed' => $result->failed,
                'lock_busy' => $result->lockBusy,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($result->lockBusy) {
            $this->info('Публикацию уже выполняет другой воркер.');

            return;
        }

        $this->info(sprintf(
            'Опубликовано событий: %d, неудачных: %d.',
            $result->published,
            $result->failed,
        ));
    }

    private function reportError(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'published' => 0,
                'failed' => 0,
                'lock_busy' => false,
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
