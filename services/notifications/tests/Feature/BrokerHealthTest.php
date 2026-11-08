<?php

declare(strict_types=1);

use App\Services\Events\Amqp\AmqpBroker;
use App\Services\Events\BrokerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeAmqpBroker;

/*
|--------------------------------------------------------------------------
| /health и команда проверки брокера (FR-NTF-548, FR-NTF-552)
|--------------------------------------------------------------------------
|
| При транспорте rabbitmq в ответе появляется аддитивная проверка `broker`,
| форма прежних полей не меняется. Недоступный брокер даёт degraded и 503.
| Реальный брокер не нужен: AmqpBroker подменяется.
|
*/

uses(RefreshDatabase::class);

/**
 * Ответ XINFO GROUPS для группы сервиса (тот же формат, что у /health).
 *
 * @return array<int, array<string, mixed>>
 */
function brokerHealthGroup(): array
{
    return [[
        'name' => 'notifications-service-test',
        'consumers' => 1,
        'pending' => 0,
        'last-delivered-id' => '0-0',
        'entries-read' => null,
        'lag' => 0,
    ]];
}

/**
 * Включает транспорт rabbitmq и подменяет брокер.
 */
function bindBrokerHealthFake(FakeAmqpBroker $broker): FakeAmqpBroker
{
    config(['notifications.transport.driver' => 'rabbitmq']);
    app()->instance(AmqpBroker::class, $broker);
    app()->forgetInstance(BrokerHealth::class);

    return $broker;
}

/**
 * Запускает команду и разбирает JSON-строку из вывода.
 *
 * @return array<string, mixed>
 */
function brokerHealthPayload(): array
{
    Artisan::call('notifications:broker-health', ['--json' => true]);
    $output = (string) Artisan::output();

    foreach (explode("\n", $output) as $line) {
        $line = trim($line);

        if (str_starts_with($line, '{"command"')) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            return $payload;
        }
    }

    return [];
}

it('добавляет проверку брокера к /health без изменения прежних полей', function (): void {
    bindFakeRedis(['xinfo' => brokerHealthGroup()]);
    bindBrokerHealthFake(new FakeAmqpBroker);

    $response = $this->getJson('/health');
    $body = (array) $response->json();

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.redis', 'ok')
        ->assertJsonPath('checks.contracts', 'ok')
        ->assertJsonPath('checks.broker', 'ok');

    expect(array_keys($body))->toBe(['status', 'checks', 'lag_seconds', 'pending', 'generated_at'])
        ->and(array_keys((array) $body['checks']))->toBe(['database', 'redis', 'contracts', 'broker']);
});

it('отвечает 503 и degraded при недоступном брокере', function (): void {
    bindFakeRedis(['xinfo' => brokerHealthGroup()]);
    $broker = new FakeAmqpBroker;
    $broker->available = false;
    bindBrokerHealthFake($broker);

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis', 'ok')
        ->assertJsonPath('checks.broker', 'error');
});

it('не добавляет проверку брокера при транспорте redis', function (): void {
    bindFakeRedis(['xinfo' => brokerHealthGroup()]);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonMissingPath('checks.broker');
});

it('отдаёт состояние брокера командой notifications:broker-health --json', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->depths['events.orders.notifications-service'] = 3;
    bindBrokerHealthFake($broker);

    $this->artisan('notifications:broker-health', ['--json' => true])
        ->assertExitCode(0);

    $payload = brokerHealthPayload();

    expect($payload['status'])->toBe('ok')
        ->and($payload['driver'])->toBe('rabbitmq')
        ->and($payload['enabled'])->toBeTrue()
        ->and($payload['connected'])->toBeTrue()
        ->and($payload['queues'])->toBeArray()
        ->and($payload['dlq'])->toBeArray();
});

it('возвращает код 1, когда брокер недоступен', function (): void {
    $broker = new FakeAmqpBroker;
    $broker->available = false;
    bindBrokerHealthFake($broker);

    $this->artisan('notifications:broker-health', ['--json' => true])
        ->assertExitCode(1);
});

it('не требует профиля broker при транспорте redis', function (): void {
    $this->artisan('notifications:broker-health', ['--json' => true])
        ->assertExitCode(0);

    $payload = brokerHealthPayload();

    expect($payload['status'])->toBe('disabled')
        ->and($payload['enabled'])->toBeFalse()
        ->and($payload['driver'])->toBe('redis');
});
