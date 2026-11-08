<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\Contracts\CourierAssignmentStrategy;
use App\Domain\Dispatch\CourierSelection;
use App\Domain\Dispatch\Exceptions\UnknownAssignmentStrategy;
use App\Domain\Dispatch\Strategies\BestEtaCourierStrategy;
use App\Domain\Dispatch\Strategies\LeastLoadedCourierStrategy;
use App\Domain\Dispatch\Strategies\NearestCourierStrategy;

/**
 * Выбор правила по имени для проверок.
 */
function dispatchSelection(): CourierSelection
{
    return new CourierSelection([
        new NearestCourierStrategy,
        new LeastLoadedCourierStrategy,
        new BestEtaCourierStrategy,
    ]);
}

it('находит правило по имени', function (string $name, CourierAssignmentStrategy $expected): void {
    expect(dispatchSelection()->forName($name))->toBeInstanceOf($expected::class);
})->with([
    ['nearest', new NearestCourierStrategy],
    ['least_loaded', new LeastLoadedCourierStrategy],
    ['best_eta', new BestEtaCourierStrategy],
]);

it('перечисляет имена всех правил', function (): void {
    expect(dispatchSelection()->names())->toEqualCanonicalizing([
        AssignmentStrategy::Nearest->value,
        AssignmentStrategy::LeastLoaded->value,
        AssignmentStrategy::BestEta->value,
    ]);
});

it('бросает доменное исключение на неизвестное правило', function (): void {
    expect(static fn () => dispatchSelection()->forName('fastest'))
        ->toThrow(UnknownAssignmentStrategy::class, 'fastest');
});

it('получает набор правил из контейнера и понимает правило по умолчанию', function (): void {
    $selection = app(CourierSelection::class);

    expect($selection->names())->toHaveCount(3)
        ->and($selection->forName((string) config('dispatch.strategy')))->toBeInstanceOf(NearestCourierStrategy::class);
});
