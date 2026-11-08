<?php

declare(strict_types=1);

use App\Domain\Notifications\Exceptions\PreferenceCriticalChannelRequired;
use App\Domain\Notifications\Exceptions\PreferenceEventTypeNotAllowed;
use App\Domain\Notifications\Exceptions\PreferenceInvalidChannel;
use App\Domain\Notifications\Exceptions\PreferenceInvalidEventType;
use App\Domain\Notifications\Exceptions\PreferenceInvalidLocale;
use App\Domain\Notifications\Exceptions\PreferenceInvalidQuietHours;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\PreferenceRow;
use App\Domain\Notifications\PreferenceValidator;
use App\Domain\Notifications\QuietHours;

/*
|--------------------------------------------------------------------------
| Валидация предпочтений (ЧТЗ 9.1 п. 10, FR-NTF-454…458, FR-NTF-460)
|--------------------------------------------------------------------------
|
| Домен проверяет набор строк без фреймворка: включённые каналы, локали,
| критичные типы событий и доступные роли типы приходят готовыми списками.
| Сначала проверяются форматы, затем бизнес-правила, поэтому код ошибки
| не зависит от порядка ключей в строке запроса.
|
*/

/**
 * @param list<string> $enabledChannels
 */
function preferenceValidator(array $enabledChannels = ['email', 'push', 'telegram']): PreferenceValidator
{
    return new PreferenceValidator(
        enabledChannels: $enabledChannels,
        supportedLocales: ['ru', 'en'],
        allowedEventTypes: [
            'order.created',
            'order.assigned',
            'order.picked_up',
            'order.delivered',
            'order.failed',
            'order.cancelled',
        ],
        criticalEventTypes: ['order.delivered', 'order.failed'],
        fallbackLocale: 'ru',
    );
}

/**
 * Строка запроса `PATCH /api/v1/preferences` для типа события.
 *
 * @param list<string> $channels
 * @param array<string, mixed>|null $quietHours
 * @return array<string, mixed>
 */
function preferenceRowInput(
    string $eventType = 'order.created',
    array $channels = ['email'],
    ?array $quietHours = null,
    ?string $locale = null,
): array {
    return [
        'event_type' => $eventType,
        'channels' => $channels,
        'quiet_hours' => $quietHours,
        'locale' => $locale,
    ];
}

it('принимает строку и убирает повторы каналов', function (): void {
    $rows = preferenceValidator()->validate([
        preferenceRowInput('order.created', ['push', 'email', 'push']),
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBeInstanceOf(PreferenceRow::class)
        ->and($rows[0]->eventType())->toBe('order.created')
        ->and($rows[0]->channels())->toBe(['push', 'email'])
        ->and($rows[0]->quietHours())->toBeNull()
        ->and($rows[0]->locale())->toBe('ru')
        ->and($rows[0]->isReset())->toBeFalse()
        ->and($rows[0]->criticalOverride())->toBeFalse();
});

it('приводит локаль к нижнему регистру и берёт язык по умолчанию для пустого значения', function (): void {
    $rows = preferenceValidator()->validate([
        preferenceRowInput('order.created', ['email'], null, ' EN '),
        preferenceRowInput('order.picked_up', ['email'], null, ''),
    ]);

    expect($rows[0]->locale())->toBe('en')
        ->and($rows[1]->locale())->toBe('ru');
});

it('отклоняет неизвестный канал кодом PREFERENCE_INVALID_CHANNEL', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.created', ['sms']),
    ]))->toThrow(PreferenceInvalidChannel::class);

    try {
        preferenceValidator()->validate([preferenceRowInput('order.created', ['sms'])]);
    } catch (PreferenceInvalidChannel $exception) {
        expect($exception->errorCode())->toBe(NotificationErrorCode::PREFERENCE_INVALID_CHANNEL);
    }
});

it('отклоняет выключенный канал кодом PREFERENCE_INVALID_CHANNEL', function (): void {
    expect(fn (): array => preferenceValidator(['email', 'push'])->validate([
        preferenceRowInput('order.created', ['telegram']),
    ]))->toThrow(PreferenceInvalidChannel::class);

    $rows = preferenceValidator(['email', 'push', 'telegram'])->validate([
        preferenceRowInput('order.created', ['telegram']),
    ]);

    expect($rows[0]->channels())->toBe(['telegram']);
});

it('отклоняет не массив каналов и нестроковое значение списка', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        ['event_type' => 'order.created', 'channels' => 'email'],
    ]))->toThrow(PreferenceInvalidChannel::class)
        ->and(fn (): array => preferenceValidator()->validate([
            ['event_type' => 'order.created', 'channels' => [42]],
        ]))->toThrow(PreferenceInvalidChannel::class);
});

it('отклоняет нестроковую локаль кодом PREFERENCE_INVALID_LOCALE', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        ['event_type' => 'order.created', 'channels' => ['email'], 'locale' => 42],
    ]))->toThrow(PreferenceInvalidLocale::class);
});

it('отклоняет локаль вне поддерживаемых кодом PREFERENCE_INVALID_LOCALE', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.created', ['email'], null, 'de'),
    ]))->toThrow(PreferenceInvalidLocale::class);

    try {
        preferenceValidator()->validate([preferenceRowInput('order.created', ['email'], null, 'de')]);
    } catch (PreferenceInvalidLocale $exception) {
        expect($exception->errorCode())->toBe(NotificationErrorCode::PREFERENCE_INVALID_LOCALE);
    }
});

it('отклоняет окно не в формате HH:MM', function (): void {
    $invalid = [
        ['from' => '22:60', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
        ['from' => '7:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
        ['from' => '22:00', 'to' => '25:00', 'timezone' => 'Europe/Moscow'],
        ['to' => '08:00', 'timezone' => 'Europe/Moscow'],
        ['from' => '22:00', 'to' => '08:00'],
    ];

    foreach ($invalid as $quietHours) {
        expect(fn (): array => preferenceValidator()->validate([
            preferenceRowInput('order.created', ['email'], $quietHours),
        ]))->toThrow(PreferenceInvalidQuietHours::class);
    }

    // Окно строкой вместо объекта тоже нарушает формат.
    expect(fn (): array => preferenceValidator()->validate([
        ['event_type' => 'order.created', 'channels' => ['email'], 'quiet_hours' => '22:00'],
    ]))->toThrow(PreferenceInvalidQuietHours::class);
});

it('отклоняет окно нулевой длины и неизвестную таймзону', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.created', ['email'], ['from' => '22:00', 'to' => '22:00', 'timezone' => 'Europe/Moscow']),
    ]))->toThrow(PreferenceInvalidQuietHours::class);

    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.created', ['email'], ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Mars/Base']),
    ]))->toThrow(PreferenceInvalidQuietHours::class);

    try {
        preferenceValidator()->validate([
            preferenceRowInput('order.created', ['email'], ['from' => '22:00', 'to' => '22:00', 'timezone' => 'UTC']),
        ]);
    } catch (PreferenceInvalidQuietHours $exception) {
        expect($exception->errorCode())->toBe(NotificationErrorCode::PREFERENCE_INVALID_QUIET_HOURS);
    }
});

it('принимает окно через полночь и таймзону из списка', function (): void {
    $rows = preferenceValidator()->validate([
        preferenceRowInput('order.created', ['email'], [
            'from' => '22:00',
            'to' => '08:00',
            'timezone' => 'Europe/Moscow',
        ]),
    ]);

    $quietHours = $rows[0]->quietHours();

    expect($quietHours)->toBeInstanceOf(QuietHours::class)
        ->and($quietHours?->from())->toBe('22:00')
        ->and($quietHours?->to())->toBe('08:00')
        ->and($quietHours?->timezone())->toBe('Europe/Moscow')
        ->and($quietHours?->toArray())->toBe([
            'from' => '22:00',
            'to' => '08:00',
            'timezone' => 'Europe/Moscow',
        ]);
});

it('требует email у критичного события', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.delivered', ['push', 'telegram']),
    ]))->toThrow(PreferenceCriticalChannelRequired::class);

    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.failed', []),
    ]))->toThrow(PreferenceCriticalChannelRequired::class);

    try {
        preferenceValidator()->validate([preferenceRowInput('order.delivered', ['push'])]);
    } catch (PreferenceCriticalChannelRequired $exception) {
        expect($exception->errorCode())->toBe(NotificationErrorCode::PREFERENCE_CRITICAL_CHANNEL_REQUIRED);
    }
});

it('помечает сохранённую строку критичного события признаком принудительного email', function (): void {
    $rows = preferenceValidator()->validate([
        preferenceRowInput('order.delivered', ['email', 'push']),
        preferenceRowInput('order.created', ['email']),
    ]);

    expect($rows[0]->criticalOverride())->toBeTrue()
        ->and($rows[1]->criticalOverride())->toBeFalse();
});

it('разрешает пустой набор каналов у некритичного события', function (): void {
    $rows = preferenceValidator()->validate([
        preferenceRowInput('order.created', []),
    ]);

    expect($rows[0]->channels())->toBe([]);
});

it('отклоняет неизвестный тип события кодом PREFERENCE_INVALID_EVENT_TYPE', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.unknown', ['email']),
    ]))->toThrow(PreferenceInvalidEventType::class);

    expect(fn (): array => preferenceValidator()->validate([
        ['channels' => ['email']],
    ]))->toThrow(PreferenceInvalidEventType::class);

    expect(fn (): array => preferenceValidator()->validate(['order.created']))
        ->toThrow(PreferenceInvalidEventType::class);
});

it('отклоняет известный, но недоступный роли тип события', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('courier.shift.started', ['push']),
    ]))->toThrow(PreferenceEventTypeNotAllowed::class);

    try {
        preferenceValidator()->validate([preferenceRowInput('courier.shift.started', ['push'])]);
    } catch (PreferenceEventTypeNotAllowed $exception) {
        expect($exception->errorCode())->toBe(NotificationErrorCode::PREFERENCE_EVENT_TYPE_NOT_ALLOWED);
    }
});

it('принимает сброс строки без каналов и окна', function (): void {
    $rows = preferenceValidator()->validate([
        ['event_type' => 'order.created', 'reset' => true],
    ]);

    expect($rows[0]->isReset())->toBeTrue()
        ->and($rows[0]->eventType())->toBe('order.created')
        ->and($rows[0]->channels())->toBe([])
        ->and($rows[0]->locale())->toBe('ru');
});

it('отклоняет сброс недоступного роли типа события', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        ['event_type' => 'user.contact_changed', 'reset' => true],
    ]))->toThrow(PreferenceEventTypeNotAllowed::class);
});

it('проверяет весь набор и не принимает ни одной строки при ошибке', function (): void {
    expect(fn (): array => preferenceValidator()->validate([
        preferenceRowInput('order.created', ['email']),
        preferenceRowInput('order.picked_up', ['sms']),
    ]))->toThrow(PreferenceInvalidChannel::class);
});

it('восстанавливает окно из сохранённого значения и отбрасывает неполное', function (): void {
    $stored = QuietHours::fromStored(['from' => '22:00', 'to' => '08:00', 'timezone' => 'UTC']);

    expect($stored)->toBeInstanceOf(QuietHours::class)
        ->and($stored?->to())->toBe('08:00')
        ->and(QuietHours::fromStored(null))->toBeNull()
        ->and(QuietHours::fromStored('22:00'))->toBeNull()
        ->and(QuietHours::fromStored(['from' => '22:00']))->toBeNull();
});
