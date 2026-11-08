<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Domain\Notifications\NotificationChannel;
use App\Models\User;
use App\Services\Notifications\NotificationPreferencesScreen;
use App\Services\Notifications\Service\NotificationServiceResult;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Настройки уведомлений пользователя (ЧТЗ 4.7, 4.8; FR-UI-401…412).
 *
 * Экран показывает матрицу «тип события × каналы», окно тихих часов и
 * привязку Telegram. Источник данных — служебный API сервиса оповещений
 * через `NotificationPreferencesScreen`: предпочтения и статус привязки
 * монолит не хранит и не кэширует (FR-OPS-405). Браузер сервис напрямую
 * не вызывает (FR-UI-406).
 *
 * Идентификатор пользователя берётся из сессии при каждом действии и
 * не попадает в свойства компонента: подменить его формой нельзя
 * (FR-NTF-461, FR-UI-405). Адрес сервиса, сервисный токен и `chat_id`
 * в свойствах отсутствуют (FR-OPS-406, FR-OPS-412).
 */
final class NotificationPreferences extends Component
{
    /**
     * Строки матрицы: тип события сервиса и отметки каналов.
     *
     * @var list<array{event_type: string, channels: array<string, bool>, source: string}>
     */
    public array $rows = [];

    public bool $quietEnabled = false;

    public ?string $quietFrom = null;

    public ?string $quietTo = null;

    public ?string $quietTimezone = null;

    /**
     * Статус привязки Telegram: absent, pending, verified или revoked.
     */
    public string $telegramStatus = 'absent';

    public ?string $telegramVerifiedAt = null;

    public ?string $deepLink = null;

    public ?string $deepLinkExpiresAt = null;

    /**
     * Сервис оповещений ответил: экран открыт, изменения доступны.
     */
    public bool $serviceAvailable = true;

    /**
     * Результат последнего действия: показывается в области `aria-live`.
     */
    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(NotificationPreferencesScreen $screen): void
    {
        $this->load($screen);
    }

    /**
     * Повторное чтение настроек, когда сервис ответил не сразу (FR-UI-407).
     */
    public function reload(NotificationPreferencesScreen $screen): void
    {
        $this->message = null;

        $this->load($screen);
    }

    /**
     * Сохранение матрицы и окна тихих часов (FR-UI-402, FR-UI-403, FR-UI-405).
     */
    public function save(NotificationPreferencesScreen $screen): void
    {
        if (! $this->serviceAvailable) {
            return;
        }

        $this->validate($this->rules());

        try {
            $result = $screen->save($this->user(), $this->payload());
        } catch (NotificationServiceUnavailable) {
            $this->markUnavailable();

            return;
        }

        if (! $result->ok) {
            $this->reportFailure($result);

            return;
        }

        $this->notify('success', (string) __('ui.notifications.messages.saved'));
    }

    /**
     * Выпуск одноразовой ссылки привязки Telegram (FR-UI-404, FR-UI-405).
     */
    public function startTelegramBinding(NotificationPreferencesScreen $screen): void
    {
        if (! $this->serviceAvailable) {
            return;
        }

        try {
            $result = $screen->startBinding($this->user());
        } catch (NotificationServiceUnavailable) {
            $this->markUnavailable();

            return;
        }

        if (! $result->ok) {
            $this->reportFailure($result);

            return;
        }

        $this->deepLink = $this->stringValue($result->data['deep_link'] ?? null);
        $this->deepLinkExpiresAt = $this->momentLabel($result->data['expires_at'] ?? null);
        $this->telegramStatus = $this->stringValue($result->data['status'] ?? null) ?? 'pending';

        $this->notify('success', (string) __('ui.notifications.messages.binding_started'));
    }

    /**
     * Отвязка Telegram после подтверждения в модальном окне (FR-UI-404).
     */
    public function unbindTelegram(NotificationPreferencesScreen $screen): void
    {
        if (! $this->serviceAvailable) {
            return;
        }

        try {
            $result = $screen->unbind($this->user());
        } catch (NotificationServiceUnavailable) {
            $this->markUnavailable();

            return;
        }

        if (! $result->ok) {
            $this->reportFailure($result);

            return;
        }

        $this->telegramStatus = 'absent';
        $this->telegramVerifiedAt = null;
        $this->deepLink = null;
        $this->deepLinkExpiresAt = null;

        $this->notify('success', (string) __('ui.notifications.messages.unbound'));
    }

    /**
     * Повторное чтение статуса привязки (FR-UI-405).
     */
    public function refreshBinding(NotificationPreferencesScreen $screen): void
    {
        if (! $this->serviceAvailable) {
            return;
        }

        try {
            $result = $screen->binding($this->user());
        } catch (NotificationServiceUnavailable) {
            $this->markUnavailable();

            return;
        }

        if (! $result->ok) {
            $this->reportFailure($result);

            return;
        }

        $binding = is_array($result->data['binding'] ?? null) ? $result->data['binding'] : [];

        $this->telegramStatus = $this->stringValue($binding['status'] ?? null) ?? 'absent';
        $this->telegramVerifiedAt = $this->momentLabel($binding['verified_at'] ?? null);

        if ($this->telegramStatus === 'verified') {
            $this->deepLink = null;
            $this->deepLinkExpiresAt = null;
        }

        $this->notify('info', (string) __('ui.notifications.messages.binding_refreshed'));
    }

    /**
     * Строки матрицы с подписями: состав каналов — из домена, подписи — из переводов.
     *
     * @return list<array{index: int, label: string, channels: list<array{value: string, label: string, checked: bool}>}>
     */
    #[Computed]
    public function eventTypes(): array
    {
        $events = [];

        foreach ($this->rows as $index => $row) {
            $channels = [];

            foreach (NotificationChannel::cases() as $channel) {
                $channels[] = [
                    'value' => $channel->value,
                    'label' => $this->channelLabel($channel),
                    'checked' => (bool) ($row['channels'][$channel->value] ?? false),
                ];
            }

            $events[] = [
                'index' => (int) $index,
                'label' => $this->eventLabel($row['event_type']),
                'channels' => $channels,
            ];
        }

        return $events;
    }

    /**
     * Часовые пояса для окна тихих часов (FR-NTF-457, FR-UI-403).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function timezoneOptions(): array
    {
        $options = [];

        foreach ($this->timezoneIdentifiers() as $identifier) {
            $options[$identifier] = $identifier;
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.account.notification-preferences');
    }

    /**
     * Состояние экрана из сервиса: матрица, окно тихих часов, привязка.
     */
    private function load(NotificationPreferencesScreen $screen): void
    {
        try {
            $state = $screen->state($this->user());
        } catch (NotificationServiceUnavailable) {
            $this->serviceAvailable = false;

            return;
        }

        $this->serviceAvailable = true;
        $this->applyState($state);
    }

    /**
     * @param array{
     *     rows: list<array{event_type: string, channels: list<string>, source: string}>,
     *     quiet: array{enabled: bool, from: string|null, to: string|null, timezone: string|null},
     *     telegram: array{status: string, verified_at: string|null}
     * } $state
     */
    private function applyState(array $state): void
    {
        $this->rows = $this->mapRows($state['rows']);

        $this->quietEnabled = $state['quiet']['enabled'];
        $this->quietFrom = $state['quiet']['from'];
        $this->quietTo = $state['quiet']['to'];
        $this->quietTimezone = $state['quiet']['timezone'];

        $this->telegramStatus = $state['telegram']['status'];
        $this->telegramVerifiedAt = $this->momentLabel($state['telegram']['verified_at']);
    }

    /**
     * Строки сервиса в состояние формы: отметка каждого канала из домена.
     *
     * @param list<array{event_type: string, channels: list<string>, source: string}> $rows
     * @return list<array{event_type: string, channels: array<string, bool>, source: string}>
     */
    private function mapRows(array $rows): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            $channels = [];

            foreach (NotificationChannel::cases() as $channel) {
                $channels[$channel->value] = in_array($channel->value, $row['channels'], true);
            }

            $mapped[] = [
                'event_type' => $row['event_type'],
                'channels' => $channels,
                'source' => $row['source'],
            ];
        }

        return $mapped;
    }

    /**
     * Набор строк для сервиса: каналы по allowlist и общее окно тихих часов.
     *
     * @return list<array{event_type: string, channels: list<string>, quiet_hours: array{from: string, to: string, timezone: string}|null}>
     */
    private function payload(): array
    {
        $quiet = $this->quietWindow();
        $payload = [];

        foreach ($this->rows as $row) {
            $channels = [];

            foreach (NotificationChannel::cases() as $channel) {
                if ((bool) ($row['channels'][$channel->value] ?? false)) {
                    $channels[] = $channel->value;
                }
            }

            $payload[] = [
                'event_type' => $row['event_type'],
                'channels' => $channels,
                'quiet_hours' => $quiet,
            ];
        }

        return $payload;
    }

    /**
     * Окно тихих часов для сохранения: выключенный переключатель даёт `null`.
     *
     * @return array{from: string, to: string, timezone: string}|null
     */
    private function quietWindow(): ?array
    {
        if (! $this->quietEnabled) {
            return null;
        }

        return [
            'from' => (string) $this->quietFrom,
            'to' => (string) $this->quietTo,
            'timezone' => (string) $this->quietTimezone,
        ];
    }

    /**
     * Правила полей формы: формат окна и таймзона проверяются на сервере.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'rows' => ['array'],
            'rows.*.channels' => ['array'],
            'rows.*.channels.*' => ['boolean'],
            'quietEnabled' => ['boolean'],
            'quietFrom' => [Rule::requiredIf($this->quietEnabled), 'nullable', 'date_format:H:i'],
            'quietTo' => [Rule::requiredIf($this->quietEnabled), 'nullable', 'date_format:H:i', 'different:quietFrom'],
            'quietTimezone' => [Rule::requiredIf($this->quietEnabled), 'nullable', Rule::in($this->timezoneIdentifiers())],
        ];
    }

    /**
     * Сервис не ответил: экран остаётся открытым, изменения блокируются (FR-UI-407).
     */
    private function markUnavailable(): void
    {
        $this->serviceAvailable = false;

        $this->notify('error', (string) __('ui.notifications.messages.service_unavailable'));
    }

    /**
     * Отказ сервиса показывается безопасным текстом по коду причины (FR-OPS-403).
     */
    private function reportFailure(NotificationServiceResult $result): void
    {
        $key = 'ui.notifications.errors.'.($result->errorCode ?? '');

        $this->notify(
            'error',
            Lang::has($key) ? (string) __($key) : (string) __('ui.notifications.messages.action_failed'),
        );
    }

    private function notify(string $variant, string $message): void
    {
        $this->messageVariant = $variant;
        $this->message = $message;
    }

    /**
     * Подпись типа события: ключ перевода без точек, которые разделяют вложенность.
     */
    private function eventLabel(string $eventType): string
    {
        $key = 'ui.notifications.events.'.str_replace('.', '_', $eventType);

        return Lang::has($key) ? (string) __($key) : $eventType;
    }

    private function channelLabel(NotificationChannel $channel): string
    {
        return (string) __('ui.notifications.channels.'.$channel->value);
    }

    /**
     * Момент времени в локальном формате интерфейса; неразобранное значение — пусто.
     */
    private function momentLabel(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $moment = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $moment
            ->timezone((string) config('app.timezone'))
            ->format((string) __('ui.formats.datetime'));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Список таймзон: значения проверяются тем же перечнем, что и в сервисе.
     *
     * @return list<string>
     */
    private function timezoneIdentifiers(): array
    {
        return array_values(DateTimeZone::listIdentifiers());
    }

    /**
     * Текущий пользователь: идентификатор берётся из сессии монолита.
     */
    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
