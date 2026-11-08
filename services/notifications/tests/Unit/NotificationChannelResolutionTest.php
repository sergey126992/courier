<?php

declare(strict_types=1);

use App\Domain\Events\EventType;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\RecipientRole;
use App\Models\ChannelBinding;
use App\Models\NotificationPreference;
use App\Models\RecipientContact;
use App\Services\Notifications\NotificationPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Резолвер каналов получателя (FR-NTF-471…476, ЧТЗ 9.1 №1…3)
|--------------------------------------------------------------------------
|
| Порядок цепочки берётся из конфигурации, сохранённая строка предпочтений
| авторитетна, пустой набор даёт матрицу M11, недоступный заранее канал
| строк не создаёт. Сеть не используется: база и фейковые привязки.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    config([
        'notifications.enabled_channels' => ['email', 'push', 'telegram'],
        'notifications.preferences.channel_priority' => ['push', 'telegram', 'email'],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Сохранённая строка предпочтений получателя.
 *
 * @param list<string> $channels
 */
function resolverPreference(int $userId, string $eventType, array $channels): void
{
    NotificationPreference::query()->create([
        'user_id' => $userId,
        'event_type' => $eventType,
        'channels' => $channels,
        'quiet_hours' => null,
        'locale' => 'ru',
        'critical_override' => false,
    ]);
}

/**
 * Контакт email получателя: канал email доступен заранее.
 */
function resolverContact(int $userId, string $email = 'sender@example.test'): void
{
    RecipientContact::factory()->create([
        'user_id' => $userId,
        'email' => $email,
        'locale' => 'ru',
    ]);
}

/**
 * Привязка Telegram: активная (verified) или отозванная.
 */
function resolverTelegram(int $userId, bool $active): void
{
    ChannelBinding::factory()->create([
        'user_id' => $userId,
        'channel' => NotificationChannel::Telegram->value,
        'address' => '777',
        'verified_at' => $active ? now()->utc() : null,
        'revoked_at' => null,
    ]);
}

/**
 * Значения каналов результата резолвера.
 *
 * @return list<string>
 */
function resolverChannelValues(int $userId, EventType $eventType, RecipientRole $role, bool $hasAssignment = true): array
{
    $resolved = app(NotificationPolicyResolver::class)->resolveFor($userId, $eventType, $role, $hasAssignment);

    return array_map(
        static fn (NotificationChannel $channel): string => $channel->value,
        $resolved->channels(),
    );
}

it('упорядочивает каналы push → telegram → email', function (): void {
    resolverPreference(7, EventType::OrderDelivered->value, ['email', 'telegram', 'push']);
    resolverContact(7);
    resolverTelegram(7, true);

    expect(resolverChannelValues(7, EventType::OrderDelivered, RecipientRole::Sender))
        ->toBe(['push', 'telegram', 'email']);
});

it('отбрасывает выключенный канал без ошибки', function (): void {
    config(['notifications.enabled_channels' => ['email', 'push']]);
    resolverPreference(7, EventType::OrderDelivered->value, ['telegram', 'email']);
    resolverContact(7);

    expect(resolverChannelValues(7, EventType::OrderDelivered, RecipientRole::Sender))->toBe(['email']);
});

it('возвращает матрицу M11 при пустом наборе предпочтений', function (): void {
    resolverContact(7);

    expect(resolverChannelValues(7, EventType::OrderDelivered, RecipientRole::Sender))
        ->toBe(['push', 'email']);
});

it('исключает Telegram без активной привязки и включает с привязкой', function (): void {
    resolverPreference(7, EventType::OrderAssigned->value, ['telegram']);

    $withoutBinding = app(NotificationPolicyResolver::class)
        ->resolveFor(7, EventType::OrderAssigned, RecipientRole::Sender, true);

    expect($withoutBinding->isEmpty())->toBeTrue()
        ->and($withoutBinding->isDisabledByUser())->toBeFalse()
        ->and($withoutBinding->unavailableChannel())->toBe(NotificationChannel::Telegram)
        ->and($withoutBinding->unavailableReason())->toBe(NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE);

    resolverTelegram(7, true);

    expect(resolverChannelValues(7, EventType::OrderAssigned, RecipientRole::Sender))->toBe(['telegram']);
});

it('учитывает роль получателя при выборе каналов матрицы', function (): void {
    resolverContact(7);

    expect(resolverChannelValues(7, EventType::OrderCancelled, RecipientRole::Sender))->toBe(['email'])
        ->and(resolverChannelValues(7, EventType::OrderCancelled, RecipientRole::Courier))->toBe(['push']);
});

it('не отдаёт курьеру каналы без назначения', function (): void {
    $resolved = app(NotificationPolicyResolver::class)
        ->resolveFor(7, EventType::OrderCancelled, RecipientRole::Courier, false);

    expect($resolved->isEmpty())->toBeTrue()
        ->and($resolved->unavailableChannel())->toBeNull()
        ->and($resolved->unavailableReason())->toBeNull();
});
