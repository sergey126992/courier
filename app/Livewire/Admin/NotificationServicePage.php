<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationChannel;
use App\Models\Order\OutboxMessage;
use App\Services\Notifications\NotificationDlqJournal;
use App\Services\Notifications\RetryOutcome;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Страница «Сервис оповещений» администратора (ЧТЗ 8.2, FR-NTF-511).
 *
 * Вкладка DLQ показывает записи очереди необработанных из служебного API
 * сервиса: фильтр по каналу и состоянию, порции и повтор открытой записи.
 * Вкладка метрик показывает снимок `/metrics` сервиса и возраст старейшего
 * неопубликованного события Outbox монолита: оба значения нужны демонстрации
 * (FR-OPS-506, FR-OPS-511). Переключателей режимов и транспорта на странице
 * нет: она только читает состояние. Доступ проверяет Gate
 * `notifications:manage`, а не роль в коде экрана; диспетчер получает отказ.
 */
final class NotificationServicePage extends Component
{
    /**
     * Фильтр канала: пустое значение — все каналы.
     */
    #[Url(as: 'channel')]
    public ?string $channel = null;

    /**
     * Фильтр состояния разбора: пустое значение — все состояния.
     */
    #[Url(as: 'state')]
    public ?string $state = null;

    /**
     * Строки очереди в общем виде страницы: только скалярные значения.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /**
     * Курсор следующей порции сервиса; null — выдача закончена.
     */
    public ?string $nextCursor = null;

    /**
     * Есть ли следующая порция: управляет кнопкой «Показать ещё».
     */
    public bool $hasMore = false;

    /**
     * Сервис недоступен: страница показывает понятное состояние.
     */
    public bool $failed = false;

    /**
     * Снимок метрик сервиса для вкладки «Метрики» (FR-OPS-511).
     *
     * @var array{
     *     counters?: list<array{channel: string, metric: string, value: int}>,
     *     dlq_depth?: list<array{channel: string, state: string, value: int}>,
     *     consumers?: list<array{stream: string, lag_seconds: int, pending: int}>,
     *     outbox_oldest_unpublished_seconds?: int|null
     * }
     */
    public array $metrics = [];

    /**
     * Метрики сервиса недоступны: вкладка показывает понятное состояние.
     */
    public bool $metricsFailed = false;

    /**
     * Возраст старейшего неопубликованного события Outbox монолита, секунды.
     */
    public ?int $monolithOutboxAgeSeconds = null;

    /**
     * Сообщение о результате повтора.
     */
    public ?string $message = null;

    /**
     * Тон сообщения о результате: success, warning или error.
     */
    public string $messageVariant = 'info';

    public function mount(NotificationDlqJournal $journal, NotificationServiceClient $client): void
    {
        Gate::authorize('notifications:manage');

        if (! $this->channelIsValid()) {
            $this->channel = null;
        }

        if (! $this->stateIsValid()) {
            $this->state = null;
        }

        $this->reload($journal);
        $this->reloadMetrics($client);
        $this->monolithOutboxAgeSeconds = $this->monolithOutboxAge();
    }

    /**
     * Обновление снимка метрик по кнопке (FR-OPS-511).
     */
    public function refreshMetrics(NotificationServiceClient $client): void
    {
        Gate::authorize('notifications:manage');

        $this->reloadMetrics($client);
        $this->monolithOutboxAgeSeconds = $this->monolithOutboxAge();
    }

    /**
     * Применение фильтров канала и состояния.
     */
    public function applyFilters(NotificationDlqJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        $this->validate([
            'channel' => ['nullable', 'string', Rule::in($this->channelValues())],
            'state' => ['nullable', 'string', Rule::in($this->stateValues())],
        ]);

        $this->channel = $this->channel === '' ? null : $this->channel;
        $this->state = $this->state === '' ? null : $this->state;
        $this->rows = [];
        $this->nextCursor = null;

        $this->reload($journal);
    }

    /**
     * Следующая порция очереди сервиса.
     */
    public function loadMore(NotificationDlqJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        if ($this->nextCursor === null) {
            return;
        }

        try {
            $page = $journal->entries($this->channel, $this->state, $this->nextCursor);
        } catch (NotificationServiceUnavailable) {
            $this->failed = true;

            return;
        }

        $this->rows = array_values(array_merge($this->rows, $page['rows']));
        $this->nextCursor = $page['next_cursor'];
        $this->hasMore = $page['next_cursor'] !== null;
        $this->failed = false;
    }

    /**
     * Повтор записи через сервис оповещений.
     */
    public function replay(int $id, NotificationDlqJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        $outcome = $journal->replay($id);

        $this->message = (string) __('notifications.admin.dlq.replay_result.'.$outcome->value);
        $this->messageVariant = match ($outcome) {
            RetryOutcome::Accepted => 'success',
            RetryOutcome::Unavailable => 'error',
            default => 'warning',
        };

        $this->rows = [];
        $this->nextCursor = null;

        $this->reload($journal);
    }

    /**
     * Подписи каналов для фильтра.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function channelOptions(): array
    {
        $options = [];

        foreach (NotificationChannel::cases() as $channel) {
            $options[$channel->value] = $channel->label();
        }

        return $options;
    }

    /**
     * Подписи состояний разбора для фильтра.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function stateOptions(): array
    {
        $options = [];

        foreach (DlqState::cases() as $state) {
            $options[$state->value] = $state->label();
        }

        return $options;
    }

    /**
     * Есть ли в снимке хотя бы одно значение: пустой ответ сервиса даёт
     * пустое состояние вкладки, а не таблицы с нулями (FR-OPS-511).
     */
    #[Computed]
    public function hasMetrics(): bool
    {
        return ($this->metrics['counters'] ?? []) !== []
            || ($this->metrics['dlq_depth'] ?? []) !== []
            || ($this->metrics['consumers'] ?? []) !== []
            || ($this->metrics['outbox_oldest_unpublished_seconds'] ?? null) !== null;
    }

    /**
     * Строки счётчиков сервиса для таблицы вкладки (FR-OPS-502).
     *
     * @return list<array{channel: string, metric: string, value: int}>
     */
    #[Computed]
    public function counterRows(): array
    {
        $labels = [
            'sent' => (string) __('notifications.admin.metrics.counter_sent'),
            'failed' => (string) __('notifications.admin.metrics.counter_failed'),
            'retried' => (string) __('notifications.admin.metrics.counter_retried'),
        ];

        $rows = [];

        foreach ($this->metrics['counters'] ?? [] as $row) {
            $rows[] = [
                'channel' => $this->channelLabel((string) $row['channel']),
                'metric' => $labels[$row['metric']] ?? $row['metric'],
                'value' => (int) $row['value'],
            ];
        }

        return $rows;
    }

    /**
     * Глубина очереди необработанных по каналам и состояниям (FR-OPS-504).
     *
     * @return list<array{channel: string, state: string, value: int}>
     */
    #[Computed]
    public function dlqDepthRows(): array
    {
        $rows = [];

        foreach ($this->metrics['dlq_depth'] ?? [] as $row) {
            $state = DlqState::tryFrom((string) $row['state']);

            $rows[] = [
                'channel' => $this->channelLabel((string) $row['channel']),
                'state' => $state?->label() ?? (string) $row['state'],
                'value' => (int) $row['value'],
            ];
        }

        return $rows;
    }

    /**
     * Лаг и pending по потокам (FR-OPS-505).
     *
     * @return list<array{stream: string, lag_seconds: int, pending: int}>
     */
    #[Computed]
    public function consumerRows(): array
    {
        $rows = [];

        foreach ($this->metrics['consumers'] ?? [] as $row) {
            $rows[] = [
                'stream' => (string) $row['stream'],
                'lag_seconds' => (int) $row['lag_seconds'],
                'pending' => (int) $row['pending'],
            ];
        }

        return $rows;
    }

    /**
     * Возраст старейшего неопубликованного события Outbox: сервис и монолит.
     *
     * Оба значения нужны, иначе FR-OPS-4 закрыт только для одного приложения
     * (FR-OPS-506).
     *
     * @return list<array{source: string, age_seconds: string}>
     */
    #[Computed]
    public function outboxRows(): array
    {
        $service = $this->metrics['outbox_oldest_unpublished_seconds'] ?? null;

        return [
            [
                'source' => (string) __('notifications.admin.metrics.outbox_service'),
                'age_seconds' => $service === null ? '—' : (string) (int) $service,
            ],
            [
                'source' => (string) __('notifications.admin.metrics.outbox_monolith'),
                'age_seconds' => $this->monolithOutboxAgeSeconds === null ? '—' : (string) $this->monolithOutboxAgeSeconds,
            ],
        ];
    }

    /**
     * Подпись количества записей для объявления обновления.
     */
    #[Computed]
    public function countLabel(): string
    {
        return (string) __('notifications.admin.dlq.count', ['count' => count($this->rows)]);
    }

    /**
     * Первая порция очереди сервиса.
     */
    private function reload(NotificationDlqJournal $journal): void
    {
        try {
            $page = $journal->entries($this->channel, $this->state);
        } catch (NotificationServiceUnavailable) {
            $this->failed = true;
            $this->rows = [];
            $this->nextCursor = null;
            $this->hasMore = false;

            return;
        }

        $this->rows = $page['rows'];
        $this->nextCursor = $page['next_cursor'];
        $this->hasMore = $page['next_cursor'] !== null;
        $this->failed = false;
    }

    /**
     * Снимок метрик сервиса: недоступность даёт понятное состояние вкладки.
     */
    private function reloadMetrics(NotificationServiceClient $client): void
    {
        try {
            $this->metrics = $client->metrics();
            $this->metricsFailed = false;
        } catch (NotificationServiceUnavailable) {
            $this->metrics = [];
            $this->metricsFailed = true;
        }
    }

    /**
     * Возраст старейшего неопубликованного события Outbox монолита (FR-OPS-506).
     */
    private function monolithOutboxAge(): ?int
    {
        try {
            $oldest = OutboxMessage::query()->whereNull('published_at')->min('created_at');
        } catch (Throwable) {
            return null;
        }

        if ($oldest === null) {
            return 0;
        }

        if ($oldest instanceof DateTimeInterface) {
            return max(0, now()->getTimestamp() - $oldest->getTimestamp());
        }

        if (! is_string($oldest) || $oldest === '') {
            return null;
        }

        try {
            $moment = Carbon::parse($oldest);
        } catch (Throwable) {
            return null;
        }

        return max(0, now()->getTimestamp() - $moment->getTimestamp());
    }

    /**
     * Подпись канала: известный канал — локализованная, иначе значение сервиса.
     */
    private function channelLabel(string $channel): string
    {
        $known = NotificationChannel::tryFrom($channel);

        return $known?->label() ?? $channel;
    }

    private function channelIsValid(): bool
    {
        return $this->channel === null || in_array($this->channel, $this->channelValues(), true);
    }

    private function stateIsValid(): bool
    {
        return $this->state === null || in_array($this->state, $this->stateValues(), true);
    }

    /**
     * @return list<string>
     */
    private function channelValues(): array
    {
        return array_map(static fn (NotificationChannel $channel): string => $channel->value, NotificationChannel::cases());
    }

    /**
     * @return list<string>
     */
    private function stateValues(): array
    {
        return array_map(static fn (DlqState $state): string => $state->value, DlqState::cases());
    }
}
