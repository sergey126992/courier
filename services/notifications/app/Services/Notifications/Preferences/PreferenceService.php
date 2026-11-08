<?php

declare(strict_types=1);

namespace App\Services\Notifications\Preferences;

use App\Domain\Events\EventType;
use App\Domain\Notifications\EffectivePreference;
use App\Domain\Notifications\EffectivePreferences;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\PreferenceRole;
use App\Domain\Notifications\PreferenceSource;
use App\Domain\Notifications\PreferenceValidator;
use App\Domain\Notifications\QuietHours;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationPolicyFactory;
use Illuminate\Support\Facades\DB;

/**
 * Эффективные предпочтения получателя и сохранение набора строк
 * (FR-NTF-452, FR-NTF-453, FR-NTF-459, ЧТЗ 8.4).
 *
 * Дефолт строки — матрица M11: единственный источник дефолта —
 * `NotificationPolicyFactory`, домен и сервис матрицу не дублируют.
 * Сохранение сначала проверяет весь набор (форматы, затем бизнес-правила),
 * и только потом пишет строки в одной транзакции: при ошибке не сохраняется
 * ни одна строка набора. Изменение предпочтений не трогает уже созданные
 * и отложенные уведомления (FR-NTF-462): сервис читает журнал только на
 * выдаче и не пересчитывает его.
 */
final class PreferenceService
{
    public function __construct(
        private readonly PreferenceRepository $repository,
        private readonly NotificationPolicyFactory $policyFactory,
    ) {}

    /**
     * Эффективные предпочтения: сохранённые строки и дефолт матрицы.
     *
     * Роль сужает выдачу до типов событий, доступных этой роли (FR-NTF-460):
     * набор приходит из конфигурации сервиса, поэтому монолиту не нужно
     * знать перечень типов событий. Без роли отдаётся объединение наборов.
     */
    public function effective(int $userId, ?PreferenceRole $role = null): EffectivePreferences
    {
        $saved = $this->repository->savedFor($userId);
        $policy = $this->policyFactory->make();
        $allowed = $this->allowedEventTypes($role);

        $preferences = [];
        $shown = [];

        foreach ($policy->eventTypes() as $eventType) {
            $value = $eventType->value;

            if (! $this->isAllowed($value, $role, $allowed)) {
                continue;
            }

            $shown[$value] = true;
            $preferences[] = $this->effectiveRow($value, $saved[$value] ?? null, $this->defaultChannels($policy, $eventType));
        }

        // Сохранённый тип события, которого нет в матрице (например, после
        // смены конфигурации), не теряется: строка показывается как настройка.
        foreach ($saved as $eventType => $model) {
            if (isset($shown[$eventType]) || ! $this->isAllowed($eventType, $role, $allowed)) {
                continue;
            }

            $preferences[] = $this->effectiveRow($eventType, $model, []);
        }

        return new EffectivePreferences($userId, $preferences);
    }

    /**
     * Проверяет набор и сохраняет его в одной транзакции.
     *
     * @param list<mixed> $rows строки запроса `PATCH /api/v1/preferences`
     * @return EffectivePreferences новое состояние после сохранения
     */
    public function save(int $userId, array $rows, ?PreferenceRole $role = null): EffectivePreferences
    {
        $validated = $this->validator($role)->validate($rows);

        DB::transaction(function () use ($userId, $validated): void {
            foreach ($validated as $row) {
                if ($row->isReset()) {
                    $this->repository->delete($userId, $row->eventType());

                    continue;
                }

                $this->repository->store($userId, $row);
            }
        });

        return $this->effective($userId, $role);
    }

    /**
     * @param list<string> $defaultChannels каналы матрицы для типа события
     */
    private function effectiveRow(
        string $eventType,
        ?NotificationPreference $model,
        array $defaultChannels,
    ): EffectivePreference {
        if ($model === null) {
            return new EffectivePreference(
                $eventType,
                $defaultChannels,
                PreferenceSource::Default,
                null,
                $this->fallbackLocale(),
            );
        }

        return new EffectivePreference(
            $eventType,
            $this->channelsOf($model),
            PreferenceSource::User,
            QuietHours::fromStored($model->quiet_hours),
            (string) $model->locale,
        );
    }

    /**
     * Дефолтные каналы типа события из матрицы M11 (FR-NTF-459).
     *
     * Матрица задаёт каналы по ролям, а предпочтения получателя — без роли,
     * поэтому строкой по умолчанию считается объединение каналов всех ролей
     * события в порядке матрицы: настройка пользователя заменяет его целиком.
     *
     * @return list<string>
     */
    private function defaultChannels(NotificationPolicy $policy, EventType $eventType): array
    {
        $channels = [];

        foreach ($policy->recipients($eventType, true) as $role) {
            foreach ($policy->channels($eventType, $role) as $channel) {
                if (! in_array($channel->value, $channels, true)) {
                    $channels[] = $channel->value;
                }
            }
        }

        return $channels;
    }

    /**
     * Каналы сохранённой строки в порядке хранения.
     *
     * @return list<string>
     */
    private function channelsOf(NotificationPreference $model): array
    {
        $channels = [];

        foreach ((array) $model->channels as $value) {
            if (is_string($value) && $value !== '') {
                $channels[] = $value;
            }
        }

        return $channels;
    }

    /**
     * Валидатор с параметрами конфигурации сервиса.
     *
     * С ролью набор типов событий сужается до набора этой роли, поэтому
     * `PREFERENCE_EVENT_TYPE_NOT_ALLOWED` отвечает по конкретной роли (FR-NTF-460).
     */
    private function validator(?PreferenceRole $role): PreferenceValidator
    {
        return new PreferenceValidator(
            enabledChannels: $this->policyFactory->enabledChannels(),
            supportedLocales: $this->stringList(config('notifications.locales.supported', [])),
            allowedEventTypes: $this->allowedEventTypes($role),
            criticalEventTypes: $this->stringList(config('notifications.preferences.critical_event_types', [])),
            fallbackLocale: $this->fallbackLocale(),
        );
    }

    /**
     * Типы событий набора: набор роли или объединение всех наборов без роли.
     *
     * @return list<string>
     */
    private function allowedEventTypes(?PreferenceRole $role): array
    {
        if ($role !== null) {
            return $this->stringList(config('notifications.preferences.'.$role->value.'_event_types', []));
        }

        $all = [];

        foreach (PreferenceRole::cases() as $case) {
            $all = array_merge($all, $this->stringList(config('notifications.preferences.'.$case->value.'_event_types', [])));
        }

        return array_values(array_unique($all));
    }

    /**
     * Попадает ли тип события в выдачу роли (без роли — ограничения нет).
     *
     * @param list<string> $allowed
     */
    private function isAllowed(string $eventType, ?PreferenceRole $role, array $allowed): bool
    {
        return $role === null || in_array($eventType, $allowed, true);
    }

    /**
     * Язык текстов по умолчанию: локаль строк без явного значения.
     */
    private function fallbackLocale(): string
    {
        return (string) config('notifications.locales.fallback', '');
    }

    /**
     * Непустые строки из значения конфигурации.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $items = [];

        foreach ((array) $value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
