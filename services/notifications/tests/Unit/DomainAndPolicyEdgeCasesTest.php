<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventType;
use App\Domain\Notifications\Exceptions\PreferenceInvalidChannel;
use App\Domain\Notifications\Exceptions\PushProviderUnavailable;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\NotificationRule;
use App\Domain\Notifications\QuietHours;
use App\Domain\Notifications\RecipientRole;
use App\Models\ChannelBinding;
use App\Models\NotificationPreference;
use App\Models\RecipientContact;
use App\Services\Notifications\Channels\TelegramApiException;
use App\Services\Notifications\NotificationPolicyFactory;
use App\Services\Notifications\NotificationPolicyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Края домена и резолвера предпочтений (ЧТЗ 9.1, пороги 9.6)
|--------------------------------------------------------------------------
|
| Тесты закрывают ветки, до которых не доходили сценарные прогоны:
| подписи каналов и ролей, геттеры политики, код провайдера доменной ошибки,
| момент события без корректного `occurred_at`, отказ сборки политики на
| неизвестных значениях матрицы, окно тихих часов вне интервала и с неверным
| форматом, а также недоступная цепочка и порядок каналов вне приоритета.
| Сеть не используется.
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
 * Строка предпочтений получателя в базе сервиса.
 *
 * @param list<mixed> $channels
 */
function edgePreference(int $userId, string $eventType, array $channels): void
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
function edgeContact(int $userId): void
{
    RecipientContact::factory()->create([
        'user_id' => $userId,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);
}

/**
 * Активная привязка Telegram получателя.
 */
function edgeTelegram(int $userId): void
{
    ChannelBinding::factory()->create([
        'user_id' => $userId,
        'channel' => NotificationChannel::Telegram->value,
        'address' => '777',
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);
}

it('даёт человекочитаемые названия каналов и ролей', function (): void {
    expect(NotificationChannel::Email->label())->toBe('Электронная почта')
        ->and(NotificationChannel::Push->label())->toBe('Push-уведомление')
        ->and(NotificationChannel::Telegram->label())->toBe('Telegram')
        ->and(RecipientRole::Sender->label())->toBe('Отправитель')
        ->and(RecipientRole::Courier->label())->toBe('Курьер');
});

it('отдаёт правила политики и признак назначения без изменений', function (): void {
    $rule = new NotificationRule(EventType::OrderAssigned, RecipientRole::Courier, [NotificationChannel::Push], true);
    $policy = new NotificationPolicy([$rule]);

    expect($policy->rules())->toBe([$rule])
        ->and($rule->requiresAssignment())->toBeTrue();
});

it('не отдаёт код провайдера для доменной ошибки без провайдера', function (): void {
    expect((new PreferenceInvalidChannel)->providerCode())->toBeNull();
});

it('приводит момент события к UTC и подставляет текущий при неверном значении', function (): void {
    $envelope = new EventEnvelope(
        'msg-1',
        EventType::OrderDelivered,
        '1.0',
        [],
        'corr-1',
        '2026-11-07T13:00:00+03:00',
    );

    expect($envelope->occurredAtUtc()->format('Y-m-d\TH:i:sP'))->toBe('2026-11-07T10:00:00+00:00');

    $empty = new EventEnvelope('msg-2', EventType::OrderDelivered, '1.0', [], null, '');
    $broken = new EventEnvelope('msg-3', EventType::OrderDelivered, '1.0', [], null, 'not-a-date');

    expect($empty->occurredAtUtc()->getTimezone()->getName())->toBe('UTC')
        ->and($broken->occurredAtUtc()->getTimezone()->getName())->toBe('UTC')
        ->and(abs($broken->occurredAtUtc()->getTimestamp() - time()))->toBeLessThan(10);
});

it('разбирает целый retry_after из ответа Telegram', function (): void {
    $exception = TelegramApiException::fromResponse(429, [
        'description' => 'Too Many Requests',
        'parameters' => ['retry_after' => 30],
    ]);

    expect($exception->status())->toBe(429)
        ->and($exception->retryAfter())->toBe(30)
        ->and($exception->description())->toBe('Too Many Requests');
});

it('останавливает сборку политики при неизвестном событии, роли или канале', function (): void {
    $factory = app(NotificationPolicyFactory::class);

    config(['notifications.policy' => ['order.unknown' => ['sender' => ['email']]]]);
    expect(fn (): NotificationPolicy => $factory->make())->toThrow(InvalidArgumentException::class);

    config(['notifications.policy' => ['order.created' => ['customer' => ['email']]]]);
    expect(fn (): NotificationPolicy => $factory->make())->toThrow(InvalidArgumentException::class);

    config(['notifications.policy' => ['order.created' => ['sender' => ['sms']]]]);
    expect(fn (): NotificationPolicy => $factory->make())->toThrow(InvalidArgumentException::class);
});

it('не откладывает уведомление вне окна и при неверном формате времени', function (): void {
    $window = QuietHours::fromValues('22:00', '08:00', 'Europe/Moscow');
    $before = CarbonImmutable::parse('2026-11-07 21:59:00', 'Europe/Moscow');

    expect($window->deferredUntil($before))->toBeNull();

    $broken = QuietHours::fromValues('25:00', '08:00', 'Europe/Moscow');

    expect($broken->deferredUntil($before))->toBeNull();
});

it('помечает канал недоступным, если весь набор выключен окружением', function (): void {
    config(['notifications.enabled_channels' => ['email']]);
    edgePreference(7, EventType::OrderAssigned->value, ['telegram']);

    $resolved = app(NotificationPolicyResolver::class)
        ->resolveFor(7, EventType::OrderAssigned, RecipientRole::Sender, true);

    expect($resolved->isEmpty())->toBeTrue()
        ->and($resolved->unavailableChannel())->toBe(NotificationChannel::Telegram)
        ->and($resolved->unavailableReason())->toBe(NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE);
});

it('пропускает нестроковые значения каналов в сохранённой строке', function (): void {
    edgePreference(7, EventType::OrderDelivered->value, [123, 'email']);
    edgeContact(7);

    $resolved = app(NotificationPolicyResolver::class)
        ->resolveFor(7, EventType::OrderDelivered, RecipientRole::Sender, true);

    expect(array_map(
        static fn (NotificationChannel $channel): string => $channel->value,
        $resolved->channels(),
    ))->toBe(['email']);
});

it('оставляет канал вне приоритета перед email', function (): void {
    config(['notifications.preferences.channel_priority' => ['push']]);
    edgePreference(7, EventType::OrderDelivered->value, ['push', 'telegram', 'email']);
    edgeContact(7);
    edgeTelegram(7);

    $resolved = app(NotificationPolicyResolver::class)
        ->resolveFor(7, EventType::OrderDelivered, RecipientRole::Sender, true);

    expect(array_map(
        static fn (NotificationChannel $channel): string => $channel->value,
        $resolved->channels(),
    ))->toBe(['push', 'telegram', 'email']);
});

it('отдаёт доменный код недоступности провайдера с причиной', function (): void {
    $previous = new RuntimeException('socket closed');

    $exception = PushProviderUnavailable::credentials($previous);

    expect($exception->errorCode())->toBe(NotificationErrorCode::PUSH_PROVIDER_UNAVAILABLE)
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->getMessage())->toContain('FCM');
});
