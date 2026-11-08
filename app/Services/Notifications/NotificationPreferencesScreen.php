<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Notifications\Service\NotificationServiceResult;
use App\Services\Notifications\Service\NotificationServiceUnavailable;

/**
 * Данные экрана настроек уведомлений (ЧТЗ 4.7, 4.8; FR-UI-401…408).
 *
 * Единственный источник — служебный API сервиса оповещений: монолит
 * предпочтения и статус привязки не хранит и не кэширует (FR-OPS-405).
 * Роль передаётся в сервис и сужает выдачу до набора типов событий роли:
 * перечня «роль → типы событий» в монолите нет, значения приходят из
 * сервиса (FR-NTF-460, FR-UI-408).
 * Браузер сервис напрямую не вызывает: все запросы идут с сервисным
 * токеном из конфигурации (FR-UI-406, FR-OPS-402).
 */
final class NotificationPreferencesScreen
{
    public function __construct(
        private readonly NotificationServiceClient $client,
    ) {}

    /**
     * Состояние экрана: строки матрицы, окно тихих часов и привязка Telegram.
     *
     * Роль передаётся в сервис: он сам решает, какие типы событий доступны
     * роли, поэтому в монолите нет перечня «роль → типы событий» (FR-NTF-460).
     *
     * @return array{
     *     rows: list<array{event_type: string, channels: list<string>, source: string}>,
     *     quiet: array{enabled: bool, from: string|null, to: string|null, timezone: string|null},
     *     telegram: array{status: string, verified_at: string|null}
     * }
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function state(User $user): array
    {
        $preferences = $this->client->preferences($user->id, $user->role->value);
        $bindings = $this->client->channelBindings($user->id);

        $rows = $this->preferenceRows($preferences->data['rows'] ?? null);

        return [
            'rows' => $this->rows($rows),
            'quiet' => $this->quietHours($rows),
            'telegram' => $this->telegram($bindings->data['binding'] ?? null),
        ];
    }

    /**
     * Сохранение матрицы и окна тихих часов (FR-UI-405).
     *
     * @param list<array{event_type: string, channels: list<string>, quiet_hours: array{from: string, to: string, timezone: string}|null}> $rows
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function save(User $user, array $rows): NotificationServiceResult
    {
        return $this->client->savePreferences($user->id, $rows, $user->role->value);
    }

    /**
     * Выпуск одноразовой ссылки привязки Telegram (FR-NTF-411, FR-UI-404).
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function startBinding(User $user): NotificationServiceResult
    {
        return $this->client->startTelegramBinding($user->id);
    }

    /**
     * Отвязка Telegram (FR-NTF-417).
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function unbind(User $user): NotificationServiceResult
    {
        return $this->client->unbindTelegram($user->id);
    }

    /**
     * Повторное чтение статуса привязки (FR-UI-405).
     *
     * @throws NotificationServiceUnavailable сервис не ответил
     */
    public function binding(User $user): NotificationServiceResult
    {
        return $this->client->channelBindings($user->id);
    }

    /**
     * Строки предпочтений сервиса без посторонних полей.
     *
     * @return list<array{event_type: string, channels: list<string>, source: string, quiet_hours: array{from: string, to: string, timezone: string}|null, locale: string}>
     */
    private function preferenceRows(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row) || ! is_string($row['event_type'] ?? null)) {
                continue;
            }

            $channels = [];

            foreach (is_array($row['channels'] ?? null) ? $row['channels'] : [] as $channel) {
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

            $rows[] = [
                'event_type' => $row['event_type'],
                'channels' => $channels,
                'source' => is_string($row['source'] ?? null) ? $row['source'] : '',
                'quiet_hours' => $quietHours,
                'locale' => is_string($row['locale'] ?? null) ? $row['locale'] : '',
            ];
        }

        return $rows;
    }

    /**
     * Строки матрицы в виде страницы: сервис уже ограничил их ролью (FR-UI-408).
     *
     * @param list<array{event_type: string, channels: list<string>, source: string, quiet_hours: array{from: string, to: string, timezone: string}|null, locale: string}> $rows
     * @return list<array{event_type: string, channels: list<string>, source: string}>
     */
    private function rows(array $rows): array
    {
        $visible = [];

        foreach ($rows as $row) {
            $visible[] = [
                'event_type' => $row['event_type'],
                'channels' => $row['channels'],
                'source' => $row['source'],
            ];
        }

        return $visible;
    }

    /**
     * Окно тихих часов: первая сохранённая строка с окном задаёт значения полей.
     *
     * @param list<array{event_type: string, channels: list<string>, source: string, quiet_hours: array{from: string, to: string, timezone: string}|null, locale: string}> $rows
     * @return array{enabled: bool, from: string|null, to: string|null, timezone: string|null}
     */
    private function quietHours(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row['quiet_hours'] !== null) {
                return [
                    'enabled' => true,
                    'from' => $row['quiet_hours']['from'],
                    'to' => $row['quiet_hours']['to'],
                    'timezone' => $row['quiet_hours']['timezone'],
                ];
            }
        }

        return ['enabled' => false, 'from' => null, 'to' => null, 'timezone' => null];
    }

    /**
     * Статус привязки Telegram из ответа сервиса.
     *
     * @return array{status: string, verified_at: string|null}
     */
    private function telegram(mixed $binding): array
    {
        if (! is_array($binding)) {
            return ['status' => 'absent', 'verified_at' => null];
        }

        return [
            'status' => is_string($binding['status'] ?? null) ? $binding['status'] : 'absent',
            'verified_at' => is_string($binding['verified_at'] ?? null) ? $binding['verified_at'] : null,
        ];
    }
}
