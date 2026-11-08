<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationStatus;
use App\Http\Controllers\HealthController;
use App\Models\Notification;
use App\Services\Notifications\Channels\FakePushProvider;
use App\Services\Notifications\Channels\PushProvider;
use App\Services\Notifications\Channels\PushProviderFactory;
use App\Services\Notifications\NotificationCounters;
use App\Services\Notifications\NotificationSender;
use App\Support\Health\HealthChecker;
use App\Support\Health\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Точки входа M13 не сломаны (пункт 13 плана M12, гейт G4)
|--------------------------------------------------------------------------
|
| M13 «Демо сервиса» опирается на форму этих символов: терминальный статус
| `NotificationSender::finalize()`, источник метрик `NotificationCounters::byChannel()`,
| формат `/health` (`HealthController`, `HealthChecker`, `HealthReport`), точку
| расширения push-провайдера, маршруты `routes/api.php` и блоки конфигурации
| сервиса. Тест фиксирует наличие и форму, а не поведение: правка M12, ломающая
| форму, обязана упасть здесь, а не в M13.
|
*/

/**
 * Типы параметров метода в порядке объявления: `int`, `?string = null`.
 *
 * @return list<string>
 */
function m13ParameterSignature(ReflectionMethod $method): array
{
    return array_map(static function (ReflectionParameter $parameter): string {
        $type = $parameter->getType();
        $base = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
        $nullable = $type instanceof ReflectionNamedType && $base !== 'mixed' && $type->allowsNull();

        return ($nullable ? '?' : '').$base.($parameter->isOptional() ? ' = null' : '');
    }, $method->getParameters());
}

/**
 * Имя возвращаемого типа или `mixed`, если тип не объявлен.
 */
function m13ReturnType(ReflectionMethod $method): string
{
    $type = $method->getReturnType();

    return $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
}

it('сохраняет точку терминального статуса NotificationSender::finalize()', function (): void {
    $method = new ReflectionMethod(NotificationSender::class, 'finalize');

    expect($method->isPrivate())->toBeTrue()
        ->and(m13ParameterSignature($method))->toBe([
            Notification::class,
            'int',
            NotificationStatus::class,
            '?string',
            '?string',
            '?string',
            '?string = null',
            '?'.Carbon::class.' = null',
        ])
        ->and(m13ReturnType($method))->toBe(NotificationStatus::class);
});

it('сохраняет источник метрик NotificationCounters::byChannel()', function (): void {
    $method = new ReflectionMethod(NotificationCounters::class, 'byChannel');

    expect($method->isPublic())->toBeTrue()
        ->and($method->isStatic())->toBeFalse()
        ->and(m13ReturnType($method))->toBe('array');
});

it('сохраняет формат отчёта состояния сервиса', function (): void {
    $controller = new ReflectionMethod(HealthController::class, '__invoke');
    $checker = new ReflectionMethod(HealthChecker::class, 'check');

    expect(m13ParameterSignature($controller))->toBe([HealthChecker::class])
        ->and(m13ReturnType($controller))->toBe(JsonResponse::class)
        ->and(m13ReturnType($checker))->toBe(HealthReport::class)
        ->and(HealthReport::STATUS_OK)->toBe('ok')
        ->and(HealthReport::STATUS_DEGRADED)->toBe('degraded');

    $report = new HealthReport(
        HealthReport::STATUS_OK,
        ['database' => 'ok', 'redis' => 'ok', 'contracts' => 'ok'],
        0,
        0,
        Carbon::parse('2026-11-07 10:00:00', 'UTC'),
    );
    $payload = $report->toArray();

    expect(array_keys($payload))->toBe(['status', 'checks', 'lag_seconds', 'pending', 'generated_at'])
        ->and($payload['status'])->toBe('ok')
        ->and($payload['lag_seconds'])->toBe(0)
        ->and($payload['pending'])->toBe(0)
        ->and($payload['generated_at'])->toBe('2026-11-07T10:00:00+00:00');
});

it('сохраняет точку расширения push-провайдера', function (): void {
    config(['notifications.push.provider' => 'fake']);

    expect((new PushProviderFactory)->make())->toBeInstanceOf(FakePushProvider::class)
        ->and(new FakePushProvider)->toBeInstanceOf(PushProvider::class);

    config(['notifications.push.provider' => 'fcm']);

    expect(fn (): PushProvider => (new PushProviderFactory)->make())
        ->toThrow(InvalidArgumentException::class);
});

it('сохраняет маршруты API журнала, повтора, push, предпочтений и привязок', function (): void {
    $names = [
        'api.notifications.index',
        'api.notifications.retry',
        'api.push-messages.index',
        'api.preferences.show',
        'api.preferences.update',
        'api.channel-bindings.index',
        'api.channel-bindings.telegram.start',
        'api.channel-bindings.telegram.confirm',
        'api.channel-bindings.telegram.revoke',
    ];

    foreach ($names as $name) {
        expect(Route::has($name))->toBeTrue();
    }

    $journal = Route::getRoutes()->getByName('api.notifications.index');

    expect($journal)->not->toBeNull()
        ->and($journal?->gatherMiddleware())->toContain('service-token');
});

it('регистрирует вебхук бота только в режиме webhook', function (): void {
    expect(Route::has('api.telegram.webhook'))->toBeFalse();

    config(['notifications.telegram.updates_mode' => 'webhook']);

    // При загрузке приложения конфигурация была `polling`, поэтому маршруты
    // перечитываются после смены режима; имена маршрутов регистрируются заново.
    Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api.php'));
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('api.telegram.webhook'))->toBeTrue();
});

it('сохраняет блоки конфигурации сервиса для M13', function (): void {
    foreach (['push', 'sender', 'outbox', 'retention', 'health'] as $block) {
        expect(config('notifications.'.$block))
            ->toBeArray()
            ->not->toBeEmpty();
    }
});
