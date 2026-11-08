<?php

declare(strict_types=1);

use App\Domain\Events\EventType;
use App\Services\Events\Exceptions\EventContractInvalid;
use App\Services\Events\Exceptions\EventContractUnknown;
use App\Services\Events\JsonSchemaValidator;
use Illuminate\Support\Facades\File;
use Opis\JsonSchema\Validator;

/**
 * Ожидаемые контракты M11: файл ↔ event_type ↔ поток (ЧТЗ 8.3, 8.4).
 *
 * @return array<string, array{event_type: string, stream: string}>
 */
function contractCatalog(): array
{
    return [
        'order-created.v1.json' => ['event_type' => 'order.created', 'stream' => 'events.orders'],
        'order-assigned.v1.json' => ['event_type' => 'order.assigned', 'stream' => 'events.orders'],
        'order-picked-up.v1.json' => ['event_type' => 'order.picked_up', 'stream' => 'events.orders'],
        'order-delivered.v1.json' => ['event_type' => 'order.delivered', 'stream' => 'events.orders'],
        'order-failed.v1.json' => ['event_type' => 'order.failed', 'stream' => 'events.orders'],
        'user-contact-changed.v1.json' => ['event_type' => 'user.contact_changed', 'stream' => 'events.contacts'],
        'notification-sent.v1.json' => ['event_type' => 'notification.sent', 'stream' => 'events.notifications'],
        'notification-failed.v1.json' => ['event_type' => 'notification.failed', 'stream' => 'events.notifications'],
    ];
}

/**
 * Общий каталог схем: единственный экземпляр для монолита и сервиса (FR-NTF-221).
 */
function contractDirectory(): string
{
    return base_path('contracts/notifications');
}

/**
 * Содержимое файла схемы как массив.
 *
 * @return array<string, mixed>
 */
function contractSchema(string $file): array
{
    $contents = file_get_contents(contractDirectory().DIRECTORY_SEPARATOR.$file);

    expect($contents)->toBeString();

    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);

    return $schema;
}

/**
 * Пример конверта события создания заказа для проверки схемы.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function contractOrderCreatedEnvelope(array $overrides = []): array
{
    $envelope = [
        'message_id' => '0c1e5f6a-1b2c-4d3e-8f90-1a2b3c4d5e6f',
        'event_type' => 'order.created',
        'schema_version' => 'v1',
        'correlation_id' => 'req-0123456789abcdef',
        'occurred_at' => '2026-05-01T12:00:00+00:00',
        'aggregate_type' => 'order',
        'aggregate_id' => 1,
        'payload' => [
            'event_id' => '0c1e5f6a-1b2c-4d3e-8f90-1a2b3c4d5e6f',
            'order_id' => 1,
            'tracking_code' => 'TRK-2026-000001',
            'sender_id' => 1,
            'status' => 'created',
            'occurred_at' => '2026-05-01T12:00:00+00:00',
            'locale' => 'ru',
            'pickup_district' => 'Северный',
            'transport' => 'light',
            'price_amount' => 19900,
            'price_currency' => 'RUB',
        ],
    ];

    return array_merge($envelope, $overrides);
}

it('хранит ровно восемь схем в общем каталоге', function (): void {
    $files = collect(File::files(contractDirectory()))
        ->map(static fn (SplFileInfo $file): string => $file->getFilename())
        ->filter(static fn (string $name): bool => str_ends_with($name, '.json'))
        ->sort()
        ->values()
        ->all();

    expect($files)->toBe([
        'notification-failed.v1.json',
        'notification-sent.v1.json',
        'order-assigned.v1.json',
        'order-created.v1.json',
        'order-delivered.v1.json',
        'order-failed.v1.json',
        'order-picked-up.v1.json',
        'user-contact-changed.v1.json',
    ]);
});

it('оформляет схемы по draft 2020-12 с константами типа и версии', function (): void {
    foreach (contractCatalog() as $file => $expected) {
        $schema = contractSchema($file);

        expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
            ->and($schema['$id'])->toContain($file)
            ->and($schema['additionalProperties'])->toBeTrue()
            ->and($schema['x-event-type'])->toBe($expected['event_type'])
            ->and($schema['x-stream'])->toBe($expected['stream'])
            ->and($schema['required'])->toContain('message_id', 'event_type', 'schema_version', 'aggregate_type', 'aggregate_id', 'occurred_at', 'payload')
            ->and($schema['properties']['event_type'])->toBe(['const' => $expected['event_type']])
            ->and($schema['properties']['schema_version'])->toBe(['const' => 'v1'])
            ->and($schema['properties']['payload']['additionalProperties'])->toBeTrue()
            ->and($schema['properties']['payload']['required'])->toContain('event_id');
    }
});

it('принимает каждый файл как схему draft 2020-12', function (): void {
    $validator = new Validator;

    foreach (contractCatalog() as $file => $expected) {
        // Валидация заведомо неполного конверта: важно, что схема разбирается
        // и применяется без исключений (ошибки полей проверяются отдельно).
        $schema = json_decode((string) json_encode(contractSchema($file)), false, 512, JSON_THROW_ON_ERROR);
        $result = $validator->validate((object) ['event_type' => $expected['event_type']], $schema);

        expect($result->isValid())->toBeFalse();
    }
});

it('не содержит копий схем вне общего каталога', function (): void {
    $directory = contractDirectory();
    $roots = ['app', 'config', 'database', 'routes', 'resources', 'tests', 'lang', 'public', 'bootstrap'];

    $copies = [];

    foreach ($roots as $root) {
        $path = base_path($root);

        if (! File::isDirectory($path)) {
            continue;
        }

        foreach (File::allFiles($path) as $file) {
            if (! str_ends_with($file->getFilename(), '.v1.json')) {
                continue;
            }

            $copies[] = $file->getPathname();
        }
    }

    // Единственный каталог схем — contracts/notifications (FR-NTF-221).
    expect($copies)->toBe([]);
    expect(File::isDirectory($directory))->toBeTrue();
});

it('согласует обязательные поля схем с реестром типов', function (): void {
    foreach (contractCatalog() as $file => $expected) {
        $type = EventType::tryFrom($expected['event_type']);

        if ($type === null) {
            // Обратные события объявляет сервис: в реестре монолита их пока нет.
            continue;
        }

        $payload = contractSchema($file)['properties']['payload'];
        $required = $payload['required'];
        $registry = $type->requiredPayloadFields();

        // Схема не требует больше того, что гарантирует код события.
        foreach ($required as $field) {
            expect($registry)->toContain($field);
        }

        // Каждое обязательное поле кода объявлено в схеме с типом.
        foreach ($registry as $field) {
            expect($payload['properties'])->toHaveKey($field);
        }
    }
});

it('описывает обязательные поля обратных событий по ЧТЗ 8.3', function (): void {
    $sent = contractSchema('notification-sent.v1.json')['properties']['payload'];

    expect($sent['required'])->toBe([
        'event_id',
        'notification_id',
        'order_id',
        'recipient_id',
        'channel',
        'status',
        'attempt',
        'occurred_at',
        'correlation_id',
    ])
        ->and($sent['additionalProperties'])->toBeTrue();

    $failed = contractSchema('notification-failed.v1.json')['properties']['payload'];

    expect($failed['required'])->toBe([...$sent['required'], 'error_code']);
});

it('читает схемы из каталога конфигурации', function (): void {
    expect(config('events.schema_path'))->toBe('contracts/notifications')
        ->and(app(JsonSchemaValidator::class)->directory())->toBe(base_path('contracts/notifications'));
});

it('принимает конверт события заказа по схеме', function (): void {
    expect(fn () => app(JsonSchemaValidator::class)->validateEvent('order.created', 'v1', contractOrderCreatedEnvelope()))
        ->not->toThrow(Throwable::class);
});

it('отклоняет конверт без обязательного поля payload', function (): void {
    $envelope = contractOrderCreatedEnvelope();
    unset($envelope['payload']['order_id']);

    $error = null;

    try {
        app(JsonSchemaValidator::class)->validateEvent('order.created', 'v1', $envelope);
    } catch (EventContractInvalid $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(EventContractInvalid::class)
        ->and($error?->errorCode())->toBe(EventContractInvalid::CODE)
        ->and($error?->keyword())->toBe('required')
        ->and($error?->pointer())->toBe('/payload');
});

it('отклоняет конверт с некорректным типом поля', function (): void {
    $envelope = contractOrderCreatedEnvelope();
    $envelope['payload']['order_id'] = 'не число';

    $error = null;

    try {
        app(JsonSchemaValidator::class)->validateEvent('order.created', 'v1', $envelope);
    } catch (EventContractInvalid $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(EventContractInvalid::class)
        ->and($error?->pointer())->toBe('/payload/order_id');
});

it('отклоняет неизвестную версию контракта', function (): void {
    expect(fn () => app(JsonSchemaValidator::class)->validateEvent('order.created', 'v9', contractOrderCreatedEnvelope()))
        ->toThrow(EventContractUnknown::class);
});

it('не проверяет типы вне контрактов оповещений', function (): void {
    expect(fn () => app(JsonSchemaValidator::class)->validateEvent('order.cancelled', 'v1', ['payload' => []]))
        ->not->toThrow(Throwable::class);
});

it('не показывает персональные данные в тексте контрактной ошибки', function (): void {
    $envelope = [
        'message_id' => '0c1e5f6a-1b2c-4d3e-8f90-1a2b3c4d5e6f',
        'event_type' => 'user.contact_changed',
        'schema_version' => 'v1',
        'correlation_id' => 'req-0123456789abcdef',
        'occurred_at' => '2026-05-01T12:00:00+00:00',
        'aggregate_type' => 'user',
        'aggregate_id' => 1,
        'payload' => [
            'event_id' => '0c1e5f6a-1b2c-4d3e-8f90-1a2b3c4d5e6f',
            // user_id не число: проверка падает на типе поля, а email остаётся в данных.
            'user_id' => 'не число',
            'email' => 'secret.sender@example.test',
            'locale' => 'ru',
            'occurred_at' => '2026-05-01T12:00:00+00:00',
        ],
    ];

    $error = null;

    try {
        app(JsonSchemaValidator::class)->validateEvent('user.contact_changed', 'v1', $envelope);
    } catch (EventContractInvalid $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(EventContractInvalid::class)
        ->and($error?->getMessage())->not->toContain('secret.sender@example.test');
});
