<?php

declare(strict_types=1);

use App\Domain\Events\EventType;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationPolicyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API предпочтений получателя (ЧТЗ 8.1, 8.3, 8.4; FR-NTF-451…462)
|--------------------------------------------------------------------------
|
| Проверяются эффективные значения и дефолт матрицы M11, сохранение upsert,
| сброс строки, источник строки, отказ критичного события без email,
| «не уведомлять» у некритичного события, отсутствие частичного сохранения
| при ошибке и права сервисного токена. Сети нет, время не используется.
|
*/

uses(RefreshDatabase::class);

const PREFERENCE_API_TOKEN = 'test-service-token';
const PREFERENCE_TEST_USER = 10;

/**
 * @return array<string, string>
 */
function preferenceApiHeaders(): array
{
    return ['Authorization' => 'Bearer '.PREFERENCE_API_TOKEN];
}

/**
 * Матрица M11 в виде объединения каналов ролей по типу события:
 * это дефолт эффективных предпочтений при отсутствии сохранённых строк.
 *
 * @return array<string, list<string>>
 */
function preferenceMatrixDefaults(): array
{
    return [
        'order.created' => ['email'],
        'order.assigned' => ['push'],
        'order.picked_up' => ['email'],
        'order.delivered' => ['email', 'push'],
        'order.failed' => ['email'],
        'order.cancelled' => ['email', 'push'],
    ];
}

beforeEach(function (): void {
    config([
        'notifications.api.token' => PREFERENCE_API_TOKEN,
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.locales.supported' => ['ru', 'en'],
        'notifications.locales.fallback' => 'ru',
        'notifications.preferences.sender_event_types' => [
            'order.created',
            'order.assigned',
            'order.picked_up',
            'order.delivered',
            'order.failed',
            'order.cancelled',
        ],
        'notifications.preferences.courier_event_types' => ['order.assigned', 'order.cancelled'],
        'notifications.preferences.critical_event_types' => ['order.delivered', 'order.failed'],
    ]);
});

it('отдаёт дефолт матрицы, когда сохранённых строк нет', function (): void {
    $response = $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER)
        ->assertOk()
        ->assertJsonPath('user_id', PREFERENCE_TEST_USER)
        ->assertJsonCount(count(preferenceMatrixDefaults()), 'preferences');

    $defaults = preferenceMatrixDefaults();
    $index = 0;

    foreach ($defaults as $eventType => $channels) {
        $response->assertJsonPath('preferences.'.$index.'.event_type', $eventType)
            ->assertJsonPath('preferences.'.$index.'.channels', $channels)
            ->assertJsonPath('preferences.'.$index.'.source', 'default')
            ->assertJsonPath('preferences.'.$index.'.quiet_hours', null)
            ->assertJsonPath('preferences.'.$index.'.locale', 'ru');
        $index++;
    }
});

it('сохраняет строку upsert и возвращает новое состояние с источником user', function (): void {
    $response = $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                [
                    'event_type' => 'order.assigned',
                    'channels' => ['email', 'push'],
                    'quiet_hours' => ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
                    'locale' => 'en',
                ],
            ],
        ])
        ->assertOk();

    $index = array_search('order.assigned', array_keys(preferenceMatrixDefaults()), true);

    expect($index)->toBeInt();

    $response->assertJsonPath('preferences.'.$index.'.event_type', 'order.assigned')
        ->assertJsonPath('preferences.'.$index.'.channels', ['email', 'push'])
        ->assertJsonPath('preferences.'.$index.'.source', 'user')
        ->assertJsonPath('preferences.'.$index.'.locale', 'en')
        ->assertJsonPath('preferences.'.$index.'.quiet_hours.from', '22:00')
        ->assertJsonPath('preferences.'.$index.'.quiet_hours.to', '08:00')
        ->assertJsonPath('preferences.'.$index.'.quiet_hours.timezone', 'Europe/Moscow');

    $saved = NotificationPreference::query()
        ->where('user_id', PREFERENCE_TEST_USER)
        ->where('event_type', 'order.assigned')
        ->first();

    expect($saved)->not->toBeNull()
        ->and($saved?->channels)->toBe(['email', 'push'])
        ->and($saved?->locale)->toBe('en')
        ->and($saved?->quiet_hours)->toEqual(['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'])
        ->and($saved?->critical_override)->toBeFalse();
});

it('не создаёт вторую строку при повторном сохранении того же набора', function (): void {
    $payload = [
        'user_id' => PREFERENCE_TEST_USER,
        'preferences' => [
            ['event_type' => 'order.created', 'channels' => ['push']],
        ],
    ];

    $this->withHeaders(preferenceApiHeaders())->patchJson('/api/v1/preferences', $payload)->assertOk();
    $this->withHeaders(preferenceApiHeaders())->patchJson('/api/v1/preferences', $payload)->assertOk();

    expect(DB::table('notification_preferences')->where('user_id', PREFERENCE_TEST_USER)->count())->toBe(1);
});

it('удаляет строку по reset и возвращает дефолт матрицы', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => PREFERENCE_TEST_USER,
        'event_type' => 'order.created',
        'channels' => ['push'],
        'critical_override' => false,
    ]);

    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                ['event_type' => 'order.created', 'reset' => true],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('preferences.0.event_type', 'order.created')
        ->assertJsonPath('preferences.0.channels', ['email'])
        ->assertJsonPath('preferences.0.source', 'default');

    expect(NotificationPreference::query()->where('user_id', PREFERENCE_TEST_USER)->count())->toBe(0);

    // Повторный сброс идемпотентен: строка остаётся удалённой (ЧТЗ 8.4).
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                ['event_type' => 'order.created', 'reset' => true],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('preferences.0.source', 'default');
});

it('помечает critical_override, когда email критичного события обязателен', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                ['event_type' => 'order.delivered', 'channels' => ['email', 'push', 'telegram']],
            ],
        ])
        ->assertOk();

    $saved = NotificationPreference::query()
        ->where('user_id', PREFERENCE_TEST_USER)
        ->where('event_type', 'order.delivered')
        ->first();

    expect($saved)->not->toBeNull()
        ->and($saved?->channels)->toBe(['email', 'push', 'telegram'])
        ->and($saved?->critical_override)->toBeTrue();
});

it('отклоняет критичное событие без email и ничего не сохраняет', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                ['event_type' => 'order.delivered', 'channels' => ['push', 'telegram']],
            ],
        ])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'PREFERENCE_CRITICAL_CHANNEL_REQUIRED')
        ->assertJsonPath('status', 422);

    expect(NotificationPreference::query()->where('user_id', PREFERENCE_TEST_USER)->count())->toBe(0);
});

it('сохраняет пустой набор каналов некритичного события как «не уведомлять»', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                ['event_type' => 'order.created', 'channels' => []],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('preferences.0.channels', [])
        ->assertJsonPath('preferences.0.source', 'user');

    $saved = NotificationPreference::query()
        ->where('user_id', PREFERENCE_TEST_USER)
        ->where('event_type', 'order.created')
        ->first();

    expect($saved?->channels)->toBe([]);
});

it('не сохраняет ни одной строки набора, если хотя бы одна строка неверна', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [
                ['event_type' => 'order.created', 'channels' => ['email']],
                ['event_type' => 'order.picked_up', 'channels' => ['sms']],
            ],
        ])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'PREFERENCE_INVALID_CHANNEL');

    expect(NotificationPreference::query()->where('user_id', PREFERENCE_TEST_USER)->count())->toBe(0);
});

it('отвечает кодами ошибок на некорректные поля строки', function (array $row, string $code): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'preferences' => [$row],
        ])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code);

    expect(NotificationPreference::query()->where('user_id', PREFERENCE_TEST_USER)->count())->toBe(0);
})->with([
    'неизвестный тип события' => [
        ['event_type' => 'order.unknown', 'channels' => ['email']],
        'PREFERENCE_INVALID_EVENT_TYPE',
    ],
    'недоступный роли тип события' => [
        ['event_type' => 'courier.shift.started', 'channels' => ['push']],
        'PREFERENCE_EVENT_TYPE_NOT_ALLOWED',
    ],
    'неизвестный канал' => [
        ['event_type' => 'order.created', 'channels' => ['sms']],
        'PREFERENCE_INVALID_CHANNEL',
    ],
    'неподдерживаемая локаль' => [
        ['event_type' => 'order.created', 'channels' => ['email'], 'locale' => 'de'],
        'PREFERENCE_INVALID_LOCALE',
    ],
    'окно нулевой длины' => [
        [
            'event_type' => 'order.created',
            'channels' => ['email'],
            'quiet_hours' => ['from' => '08:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
        ],
        'PREFERENCE_INVALID_QUIET_HOURS',
    ],
    'неизвестная таймзона' => [
        [
            'event_type' => 'order.created',
            'channels' => ['email'],
            'quiet_hours' => ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Mars/Base'],
        ],
        'PREFERENCE_INVALID_QUIET_HOURS',
    ],
]);

it('отвечает 401 без сервисного токена в формате problem+json', function (): void {
    $this->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER)
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_SERVICE_TOKEN');

    $this->patchJson('/api/v1/preferences', [
        'user_id' => PREFERENCE_TEST_USER,
        'preferences' => [['event_type' => 'order.created', 'channels' => ['email']]],
    ])
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_SERVICE_TOKEN');
});

it('отклоняет запрос без user_id как problem+json 422', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('показывает сохранённую строку типа события, которого нет в матрице', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => PREFERENCE_TEST_USER,
        'event_type' => 'order.delivered',
        'channels' => ['email', 42],
        'locale' => 'ru',
        'critical_override' => true,
    ]);

    config(['notifications.policy' => ['order.created' => ['sender' => ['email']]]]);

    $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER)
        ->assertOk()
        ->assertJsonCount(2, 'preferences')
        ->assertJsonPath('preferences.0.event_type', 'order.created')
        ->assertJsonPath('preferences.0.source', 'default')
        ->assertJsonPath('preferences.1.event_type', 'order.delivered')
        ->assertJsonPath('preferences.1.channels', ['email'])
        ->assertJsonPath('preferences.1.source', 'user');
});

it('паритет: пустой набор предпочтений даёт ровно матрицу M11', function (): void {
    $policy = (new NotificationPolicyFactory)->make();

    foreach (preferenceMatrixDefaults() as $eventType => $channels) {
        $fromMatrix = [];

        foreach ($policy->recipients(EventType::from($eventType), true) as $role) {
            foreach ($policy->channels(EventType::from($eventType), $role) as $channel) {
                if (! in_array($channel->value, $fromMatrix, true)) {
                    $fromMatrix[] = $channel->value;
                }
            }
        }

        expect($fromMatrix)->toBe($channels, $eventType);
    }

    $response = $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER)
        ->assertOk();

    foreach (array_keys(preferenceMatrixDefaults()) as $index => $eventType) {
        $response->assertJsonPath('preferences.'.$index.'.event_type', $eventType)
            ->assertJsonPath('preferences.'.$index.'.source', 'default');
    }
});

it('фильтрует предпочтения по роли получателя', function (string $role, array $expected): void {
    $response = $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER.'&role='.$role)
        ->assertOk()
        ->assertJsonPath('user_id', PREFERENCE_TEST_USER)
        ->assertJsonCount(count($expected), 'preferences');

    foreach ($expected as $index => $eventType) {
        $response->assertJsonPath('preferences.'.$index.'.event_type', $eventType);
    }
})->with([
    'отправитель видит все типы событий' => ['sender', [
        'order.created',
        'order.assigned',
        'order.picked_up',
        'order.delivered',
        'order.failed',
        'order.cancelled',
    ]],
    'курьер видит только свои' => ['courier', ['order.assigned', 'order.cancelled']],
    'диспетчер не получает автоматических уведомлений' => ['dispatcher', []],
    'администратор не получает автоматических уведомлений' => ['admin', []],
]);

it('без роли отдаёт объединение наборов типов событий', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER)
        ->assertOk()
        ->assertJsonCount(count(preferenceMatrixDefaults()), 'preferences');
});

it('не отдаёт роли строку, сохранённую по другому набору', function (): void {
    NotificationPreference::query()->create([
        'user_id' => PREFERENCE_TEST_USER,
        'event_type' => 'order.created',
        'channels' => ['email', 'push'],
        'locale' => 'ru',
        'critical_override' => false,
    ]);

    $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER.'&role=courier')
        ->assertOk()
        ->assertJsonCount(2, 'preferences')
        ->assertJsonPath('preferences.0.event_type', 'order.assigned')
        ->assertJsonPath('preferences.1.event_type', 'order.cancelled');
});

it('отклоняет неизвестную роль как problem+json 422', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->getJson('/api/v1/preferences?user_id='.PREFERENCE_TEST_USER.'&role=manager')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('отклоняет при сохранении тип события, недоступный роли', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'role' => 'courier',
            'preferences' => [
                ['event_type' => 'order.created', 'channels' => ['email']],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PREFERENCE_EVENT_TYPE_NOT_ALLOWED');

    expect(NotificationPreference::query()->where('user_id', PREFERENCE_TEST_USER)->count())->toBe(0);
});

it('сохраняет предпочтения в границах набора роли', function (): void {
    $this->withHeaders(preferenceApiHeaders())
        ->patchJson('/api/v1/preferences', [
            'user_id' => PREFERENCE_TEST_USER,
            'role' => 'courier',
            'preferences' => [
                ['event_type' => 'order.cancelled', 'channels' => ['telegram']],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'preferences')
        ->assertJsonPath('preferences.0.event_type', 'order.assigned')
        ->assertJsonPath('preferences.1.event_type', 'order.cancelled')
        ->assertJsonPath('preferences.1.channels', ['telegram'])
        ->assertJsonPath('preferences.1.source', 'user');
});
