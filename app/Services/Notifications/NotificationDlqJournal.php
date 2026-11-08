<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationChannel;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Illuminate\Support\Carbon;

/**
 * Источник данных вкладки DLQ административной страницы (ЧТЗ 8.2, FR-NTF-511).
 *
 * Единственный источник — служебный API сервиса оповещений: локального
 * списка в монолите нет, контакты и токены не кэшируются. Наружу отдаются
 * только скалярные строки без персональных данных; недоступность сервиса
 * транслируется исключением, а страница показывает понятное состояние.
 */
final class NotificationDlqJournal
{
    public function __construct(
        private readonly NotificationServiceClient $client,
    ) {}

    /**
     * Записи очереди необработанных с фильтрами и курсором.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function entries(?string $channel = null, ?string $state = null, ?string $cursor = null): array
    {
        $page = $this->client->dlqEntries(
            $this->pageSize(),
            $channel === null || $channel === '' ? null : $channel,
            $state === null || $state === '' ? null : $state,
            $cursor,
        );

        return [
            'rows' => array_map(fn (array $row): array => $this->row($row), $page['rows']),
            'next_cursor' => $page['next_cursor'],
        ];
    }

    /**
     * Повтор записи через API сервиса (FR-NTF-506, FR-NTF-511).
     */
    public function replay(int $id): RetryOutcome
    {
        try {
            $result = $this->client->replayDlq($id);
        } catch (NotificationServiceUnavailable) {
            return RetryOutcome::Unavailable;
        }

        return match ($result->status) {
            202 => RetryOutcome::Accepted,
            404 => RetryOutcome::NotFound,
            default => RetryOutcome::Conflict,
        };
    }

    /**
     * Строка DLQ в общем виде строки журнала без персональных данных.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $state = DlqState::tryFrom((string) ($row['state'] ?? ''));
        $channel = (string) ($row['channel'] ?? '');
        $failedAt = $row['failed_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'notification_id' => (int) ($row['notification_id'] ?? 0),
            'channel' => $channel,
            'channel_label' => self::channelLabel($channel),
            'state' => $state instanceof DlqState ? $state->value : (string) ($row['state'] ?? ''),
            'status' => $state instanceof DlqState ? $state->tone() : '',
            'status_label' => $state instanceof DlqState ? $state->label() : (string) ($row['state'] ?? ''),
            'replayable' => $state instanceof DlqState && $state->canReplay(),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'replay_count' => (int) ($row['replay_count'] ?? 0),
            'error_code' => is_scalar($row['error_code'] ?? null) ? (string) $row['error_code'] : null,
            'event_type' => (string) ($row['event_type'] ?? ''),
            'created_at_label' => is_string($failedAt) ? self::momentLabel(Carbon::parse($failedAt)) : '',
        ];
    }

    private static function channelLabel(string $channel): string
    {
        $value = NotificationChannel::tryFrom($channel);

        return $value?->label() ?? $channel;
    }

    private static function momentLabel(?Carbon $moment): string
    {
        return $moment === null ? '' : $moment->timezone((string) config('app.timezone'))->format('d.m.Y H:i');
    }

    private function pageSize(): int
    {
        return max(1, (int) config('notifications.admin.page_size', 20));
    }
}
