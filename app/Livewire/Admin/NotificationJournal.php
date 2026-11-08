<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Notifications\NotificationChannel;
use App\Services\Notifications\NotificationAdminJournal;
use App\Services\Notifications\RetryOutcome;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Журнал необработанных уведомлений администратора (ЧТЗ 8.2, FR-NTF-293).
 *
 * Единственный источник — служебный API сервиса оповещений: компонент
 * не ходит в базу и не выбирает режим (FR-NTF-405, FR-NTF-406). Фильтр
 * канала и повтор выполняет сервис. Доступ проверяет Gate
 * `notifications:manage`, а не роль в коде экрана.
 */
final class NotificationJournal extends Component
{
    /**
     * Фильтр канала: пустое значение — все каналы.
     */
    #[Url(as: 'channel')]
    public ?string $channel = null;

    /**
     * Строки журнала в общем виде страницы: только скалярные значения,
     * поэтому состояние переносимо между запросами.
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
     * Активный отправитель недоступен: страница показывает понятное состояние.
     */
    public bool $failed = false;

    /**
     * Сообщение о результате повтора.
     */
    public ?string $message = null;

    /**
     * Тон сообщения о результате: success, warning или error.
     */
    public string $messageVariant = 'info';

    public function mount(NotificationAdminJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        if (! $this->channelIsValid()) {
            $this->channel = null;
        }

        $this->reload($journal);
    }

    /**
     * Применение фильтра канала.
     */
    public function applyFilters(NotificationAdminJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        $this->validate([
            'channel' => ['nullable', 'string', Rule::in($this->channelValues())],
        ]);

        $this->channel = $this->channel === '' ? null : $this->channel;
        $this->rows = [];
        $this->nextCursor = null;

        $this->reload($journal);
    }

    /**
     * Следующая порция журнала сервиса.
     */
    public function loadMore(NotificationAdminJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        if ($this->nextCursor === null) {
            return;
        }

        try {
            $page = $journal->failed($this->channel, $this->nextCursor);
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
     * Повтор уведомления через сервис оповещений.
     */
    public function retry(int $id, NotificationAdminJournal $journal): void
    {
        Gate::authorize('notifications:manage');

        $outcome = $journal->retry($id);

        $this->message = (string) __('notifications.admin.retry.'.$outcome->value);
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
     * Подпись количества необработанных для объявления обновления.
     */
    #[Computed]
    public function countLabel(): string
    {
        return (string) __('notifications.admin.count', ['count' => count($this->rows)]);
    }

    /**
     * Первая порция журнала сервиса.
     */
    private function reload(NotificationAdminJournal $journal): void
    {
        try {
            $page = $journal->failed($this->channel);
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

    private function channelIsValid(): bool
    {
        return $this->channel === null || in_array($this->channel, $this->channelValues(), true);
    }

    /**
     * @return list<string>
     */
    private function channelValues(): array
    {
        return array_map(static fn (NotificationChannel $channel): string => $channel->value, NotificationChannel::cases());
    }
}
