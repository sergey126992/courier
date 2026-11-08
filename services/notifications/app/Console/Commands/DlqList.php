<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationChannel;
use App\Models\DlqEntry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Список очереди необработанных из консоли (ЧТЗ 8.6, FR-NTF-505).
 *
 * Ключи: `--channel=`, `--state=`, `--limit=`, `--json`. Порядок — по времени
 * отказа, размер порции ограничен конфигурацией `notifications.dlq`.
 * В выводе только идентификаторы, канал, состояние, причина и моменты
 * времени: персональных данных и текстов сообщений нет (FR-NTF-512).
 *
 * Коды возврата: 0 — список построен; 2 — ошибка ключа, конфигурации
 * или выполнения.
 */
final class DlqList extends Command
{
    /**
     * Список построен.
     */
    private const EXIT_OK = 0;

    /**
     * Ошибка ключа, конфигурации или выполнения.
     */
    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'notifications:dlq-list
        {--channel= : Канал доставки: email, push или telegram}
        {--state= : Состояние разбора: open, replayed или resolved}
        {--limit= : Размер порции; по умолчанию — из конфигурации}
        {--json : Вывод результата в JSON}';

    /**
     * @var string
     */
    protected $description = 'Показывает записи очереди необработанных с фильтрами';

    public function handle(): int
    {
        try {
            $channel = $this->channel();
            $state = $this->state();
            $limit = $this->limit();
        } catch (InvalidArgumentException $exception) {
            return $this->reportError($exception->getMessage());
        }

        try {
            $query = DlqEntry::query();

            if ($channel !== null) {
                $query->where('channel', $channel);
            }

            if ($state !== null) {
                $query->where('state', $state);
            }

            /** @var list<DlqEntry> $entries */
            $entries = $query
                ->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->all();
        } catch (Throwable) {
            return $this->reportError('Чтение очереди необработанных завершилось ошибкой.');
        }

        $this->render($entries);

        return self::EXIT_OK;
    }

    /**
     * Канал фильтра: пустое значение — все каналы.
     *
     * @throws InvalidArgumentException неизвестный канал
     */
    private function channel(): ?string
    {
        $value = $this->option('channel');

        if ($value === null || $value === '') {
            return null;
        }

        foreach (NotificationChannel::cases() as $channel) {
            if ($channel->value === $value) {
                return $channel->value;
            }
        }

        throw new InvalidArgumentException('Неизвестный канал: допустимы email, push и telegram.');
    }

    /**
     * Состояние фильтра: пустое значение — все состояния.
     *
     * @throws InvalidArgumentException неизвестное состояние
     */
    private function state(): ?string
    {
        $value = $this->option('state');

        if ($value === null || $value === '') {
            return null;
        }

        $state = DlqState::tryFrom((string) $value);

        if ($state === null) {
            throw new InvalidArgumentException('Неизвестное состояние: допустимы open, replayed и resolved.');
        }

        return $state->value;
    }

    /**
     * Размер порции из ключа или конфигурации в пределах лимита.
     *
     * @throws InvalidArgumentException значение не положительное целое число
     */
    private function limit(): int
    {
        $value = $this->option('limit');

        if ($value === null || $value === '') {
            return max(1, (int) config('notifications.dlq.page_limit'));
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('Флаг --limit должен быть положительным целым числом.');
        }

        $max = max(1, (int) config('notifications.dlq.max_page_limit'));

        return min((int) $value, $max);
    }

    /**
     * Вывод списка: только скалярные поля снимка.
     *
     * @param list<DlqEntry> $entries
     */
    private function render(array $entries): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(static fn (DlqEntry $entry): array => [
            'id' => (int) $entry->getKey(),
            'notification_id' => (int) $entry->notification_id,
            'channel' => (string) $entry->channel,
            'state' => $entry->state->value,
            'event_type' => (string) $entry->event_type,
            'error_class' => $entry->error_class,
            'error_code' => $entry->error_code,
            'attempts' => (int) $entry->attempts,
            'replay_count' => (int) $entry->replay_count,
            'failed_at' => $entry->failed_at->toIso8601String(),
        ], $entries);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['count' => count($rows), 'entries' => $rows],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        if ($rows === []) {
            $this->info('Записей очереди необработанных нет.');

            return;
        }

        $this->table(
            ['ID', 'Уведомление', 'Канал', 'Состояние', 'Событие', 'Код причины', 'Попыток', 'Повторов', 'Отказ'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['notification_id'],
                $row['channel'],
                $row['state'],
                $row['event_type'],
                $row['error_code'] ?? '—',
                $row['attempts'],
                $row['replay_count'],
                $row['failed_at'],
            ], $rows),
        );

        $this->info(sprintf('Записей: %d.', count($rows)));
    }

    private function reportError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['error' => $message, 'count' => 0, 'entries' => []],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::EXIT_ERROR;
    }
}
