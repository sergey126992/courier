<?php

declare(strict_types=1);

use App\Domain\Events\EventType;
use App\Domain\Notifications\Exceptions\NotificationTemplateNotFound;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Notifications\RecipientRole;
use App\Services\Notifications\NotificationPolicyFactory;
use App\Services\Notifications\NotificationPolicyResolver;
use App\Services\Notifications\NotificationTexts;
use Illuminate\Support\Facades\Lang;

/*
|--------------------------------------------------------------------------
| Политика каналов и тексты сервиса (FR-NTF-241…247, ЧТЗ 9.1)
|--------------------------------------------------------------------------
|
| Матрица перенесена из монолита без расширения: набор каналов каждого
| события сверяется с таблицей FR-NTF-242, а тексты проверяются на
| отсутствие персональных данных (FR-NTF-258).
|
*/

/**
 * Каналы политики по ролям для события.
 *
 * @return array<string, list<string>>
 */
function policyChannels(NotificationPolicy $policy, EventType $eventType, bool $hasAssignment = true): array
{
    $channels = [];

    foreach ($policy->recipients($eventType, $hasAssignment) as $role) {
        $channels[$role->value] = array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            $policy->channels($eventType, $role),
        );
    }

    return $channels;
}

/**
 * Все строки языкового файла текстов.
 *
 * @param array<mixed> $values
 * @return list<string>
 */
function templateStrings(array $values): array
{
    $strings = [];

    foreach ($values as $value) {
        if (is_array($value)) {
            $strings = array_merge($strings, templateStrings($value));

            continue;
        }

        $strings[] = (string) $value;
    }

    return $strings;
}

it('сохраняет каналы каждого события согласно таблице FR-NTF-242', function (): void {
    $policy = (new NotificationPolicyFactory)->make();

    $expected = [
        'order.created' => ['sender' => ['email']],
        'order.assigned' => ['sender' => ['push'], 'courier' => ['push']],
        'order.picked_up' => ['sender' => ['email']],
        'order.delivered' => ['sender' => ['email', 'push']],
        'order.failed' => ['sender' => ['email']],
        'order.cancelled' => ['sender' => ['email'], 'courier' => ['push']],
    ];

    foreach ($expected as $eventType => $roles) {
        expect(policyChannels($policy, EventType::from($eventType)))->toBe($roles, $eventType);
    }
});

it('не порождает уведомлений для события без правил', function (): void {
    $policy = (new NotificationPolicyFactory)->make();

    expect($policy->notifies(EventType::CourierShiftStarted))->toBeFalse()
        ->and($policy->notifies(EventType::CourierShiftEnded))->toBeFalse()
        ->and($policy->recipients(EventType::CourierShiftStarted, true))->toBe([]);
});

it('не отдаёт курьеру отмену без назначения', function (): void {
    $policy = (new NotificationPolicyFactory)->make();

    expect($policy->recipients(EventType::OrderCancelled, false))->toBe([RecipientRole::Sender])
        ->and($policy->channels(EventType::OrderCancelled, RecipientRole::Courier))->toBe([NotificationChannel::Push]);
});

it('отбрасывает выключенный канал без ошибки', function (): void {
    config(['notifications.enabled_channels' => ['email']]);

    $policy = (new NotificationPolicyFactory)->make();

    expect(policyChannels($policy, EventType::OrderDelivered))->toBe(['sender' => ['email']]);
});

it('принимает пустой набор предпочтений как политику по умолчанию', function (): void {
    $resolver = app(NotificationPolicyResolver::class);

    $resolved = $resolver->resolve([]);
    $default = (new NotificationPolicyFactory)->make();

    expect($resolved)->toBeInstanceOf(NotificationPolicy::class)
        ->and($resolved->eventTypes())->toBe($default->eventTypes());

    foreach ($default->eventTypes() as $eventType) {
        expect(policyChannels($resolved, $eventType))->toBe(policyChannels($default, $eventType));
    }
});

it('считает неизвестный шаблон бизнес-ошибкой', function (): void {
    expect(fn (): NotificationTemplate => NotificationTemplate::for(EventType::UserContactChanged, NotificationChannel::Email))
        ->toThrow(NotificationTemplateNotFound::class);
});

it('определяет шаблон парой «событие — канал»', function (): void {
    expect(NotificationTemplate::has(EventType::OrderDelivered, NotificationChannel::Push))->toBeTrue()
        ->and(NotificationTemplate::has(EventType::OrderCreated, NotificationChannel::Push))->toBeFalse()
        ->and(NotificationTemplate::for(EventType::OrderDelivered, NotificationChannel::Push))
        ->toBe(NotificationTemplate::OrderDeliveredPush);
});

it('берёт локаль из события с фолбэком на язык текстов', function (): void {
    $texts = new NotificationTexts;

    expect($texts->locale('ru'))->toBe('ru')
        ->and($texts->locale('en'))->toBe('ru')
        ->and($texts->locale(null))->toBe('ru');
});

it('отдаёт текст шаблона с подстановками номера, статуса и ссылки трекинга', function (): void {
    $texts = new NotificationTexts;

    $text = $texts->text(NotificationTemplate::OrderDeliveredEmail, 'ru', [
        'number' => '1001',
        'status' => 'delivered',
        'tracking_url' => 'http://localhost:8000/track/TRK-42',
    ]);

    expect($text['subject'])->toContain('1001')
        ->and($text['body'])->toContain('1001')
        ->and($text['body'])->toContain('delivered');
});

it('не содержит персональных данных в текстах шаблонов', function (): void {
    /** @var array<string, mixed> $templates */
    $templates = (array) Lang::get('notifications.templates', [], 'ru');

    expect($templates)->not->toBeEmpty();

    foreach (templateStrings($templates) as $text) {
        expect($text)->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/')
            ->and($text)->not->toMatch('/\+?\d[\d\s()-]{7,}\d/');
    }

    foreach (NotificationTemplate::cases() as $template) {
        expect($templates)->toHaveKey($template->value);
    }
});
