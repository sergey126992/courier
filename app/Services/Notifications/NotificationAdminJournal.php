<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationStatus;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Illuminate\Support\Carbon;

/**
 * Источник данных админ-страниц уведомлений (ЧТЗ 8.2; FR-NTF-405).
 *
 * Единственный источник — служебный API сервиса оповещений: локального
 * журнала в монолите нет, режимов доставки тоже (FR-NTF-403, FR-NTF-406).
 * Недоступность сервиса транслируется исключением `NotificationServiceUnavailable`,
 * а страница показывает понятное состояние вместо `500` (FR-NTF-294).
 *
 * Наружу отдаются только скалярные строки без персональных данных: номер
 * записи, канал, состояние, число попыток, код причины и моменты времени.
 */
final class NotificationAdminJournal
{
    public function __construct(
        private readonly NotificationServiceClient $client,
    ) {}

    /**
     * Необработанные уведомления сервиса.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function failed(?string $channel = null, ?string $cursor = null): array
    {
        $limit = $this->pageSize();
        $channel = $channel === null || $channel === '' ? null : $channel;

        $page = $this->client->failed($limit, $channel, $cursor);

        return [
            'rows' => array_map(fn (array $row): array => $this->serviceRow($row), $page['rows']),
            'next_cursor' => $page['next_cursor'],
        ];
    }

    /**
     * Журнал симулятора push сервиса.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function pushMessages(): array
    {
        $limit = $this->pageSize();

        $page = $this->client->pushMessages($limit);

        return [
            'rows' => array_map(fn (array $row): array => $this->servicePushRow($row), $page['rows']),
            'next_cursor' => $page['next_cursor'],
        ];
    }

    /**
     * Ручной повтор уведомления через API сервиса (FR-NTF-276, FR-NTF-405).
     */
    public function retry(int $id): RetryOutcome
    {
        try {
            return match ($this->client->retry($id)) {
                202 => RetryOutcome::Accepted,
                404 => RetryOutcome::NotFound,
                default => RetryOutcome::Conflict,
            };
        } catch (NotificationServiceUnavailable) {
            return RetryOutcome::Unavailable;
        }
    }

    /**
     * Строка журнала сервиса в общем виде страницы (FR-NTF-275).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serviceRow(array $row): array
    {
        $status = NotificationStatus::tryFrom((string) ($row['status'] ?? ''));
        $created = $row['created_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'channel' => (string) ($row['channel'] ?? ''),
            'channel_label' => self::channelLabel((string) ($row['channel'] ?? '')),
            'status' => $status instanceof NotificationStatus ? $status->value : (string) ($row['status'] ?? ''),
            'status_label' => $status instanceof NotificationStatus ? $status->label() : (string) ($row['status'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'error_code' => is_scalar($row['error_code'] ?? null) ? (string) $row['error_code'] : null,
            'event_type' => (string) ($row['event_type'] ?? ''),
            'created_at_label' => is_string($created) ? self::momentLabel(Carbon::parse($created)) : '',
        ];
    }

    /**
     * Сообщение симулятора сервиса в общем виде страницы (FR-NTF-254).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function servicePushRow(array $row): array
    {
        $sent = $row['sent_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'notification_id' => $row['notification_id'] ?? null,
            'body' => is_scalar($row['body'] ?? null) ? (string) $row['body'] : '',
            'sent_at_label' => is_string($sent) ? self::momentLabel(Carbon::parse($sent)) : '',
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
