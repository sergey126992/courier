<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Services\Geography\CachedDistanceProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Провайдер-заглушка из общего набора хелперов тестов.
 */
function countingDistanceProvider(float $kilometers = 12.5, ?Throwable $failure = null): FakeDistanceProvider
{
    return new FakeDistanceProvider($kilometers, $failure);
}

function cachedProvider(DistanceProvider $inner, int $keyDecimals = 3): CachedDistanceProvider
{
    return new CachedDistanceProvider(
        inner: $inner,
        ttlSeconds: 3600,
        keyDecimals: $keyDecimals,
        lockTtlSeconds: 10,
        lockWaitSeconds: 5,
    );
}

function moscowPoint(): Coordinates
{
    return new Coordinates(55.755826, 37.6173);
}

function spbPoint(): Coordinates
{
    return new Coordinates(59.938784, 30.314997);
}

beforeEach(function (): void {
    Cache::flush();
});

it('не вызывает провайдера повторно для той же пары координат', function (): void {
    $inner = countingDistanceProvider();
    $provider = cachedProvider($inner);

    $first = $provider->between(moscowPoint(), spbPoint());
    $second = $provider->between(moscowPoint(), spbPoint());

    expect($inner->calls)->toBe(1)
        ->and($second->kilometers())->toBe($first->kilometers())
        ->and($first->kilometers())->toBe(12.5);
});

it('считает пару одинаковой независимо от направления', function (): void {
    $inner = countingDistanceProvider();
    $provider = cachedProvider($inner);

    $provider->between(moscowPoint(), spbPoint());
    $provider->between(spbPoint(), moscowPoint());

    expect($inner->calls)->toBe(1);
});

it('округляет координаты ключа до трёх знаков', function (): void {
    $inner = countingDistanceProvider();
    $provider = cachedProvider($inner);

    $provider->between(new Coordinates(55.755826, 37.6173), new Coordinates(59.938784, 30.314997));
    $provider->between(new Coordinates(55.75581, 37.61732), new Coordinates(59.938792, 30.31499));

    // Различие в четвёртом знаке на ключ не влияет: это одна и та же точка для кэша.
    expect($inner->calls)->toBe(1);
});

it('считает разные пары координат раздельно', function (): void {
    $inner = countingDistanceProvider();
    $provider = cachedProvider($inner);

    $provider->between(moscowPoint(), spbPoint());
    $provider->between(moscowPoint(), new Coordinates(56.0, 37.0));

    expect($inner->calls)->toBe(2);
});

it('не кэширует сбой провайдера', function (): void {
    $inner = countingDistanceProvider(failure: DistanceProviderUnavailable::forProvider('локальный', 'таймаут'));
    $provider = cachedProvider($inner);

    expect(static fn () => $provider->between(moscowPoint(), spbPoint()))
        ->toThrow(DistanceProviderUnavailable::class);

    expect(static fn () => $provider->between(moscowPoint(), spbPoint()))
        ->toThrow(DistanceProviderUnavailable::class);

    expect($inner->calls)->toBe(2)
        ->and(Cache::get('geography:distance:55.756,37.617|59.939,30.315'))->toBeNull();
});

it('сохраняет расстояние в кэш на время жизни из конфигурации', function (): void {
    $provider = cachedProvider(countingDistanceProvider(12.5));

    $provider->between(moscowPoint(), spbPoint());

    expect(Cache::get('geography:distance:55.756,37.617|59.939,30.315'))->toBe(12.5);
});
