<?php

declare(strict_types=1);

use App\Domain\Dispatch\Contracts\CourierAssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;
use App\Domain\Dispatch\Strategies\BestEtaCourierStrategy;
use App\Domain\Dispatch\Strategies\LeastLoadedCourierStrategy;
use App\Domain\Dispatch\Strategies\NearestCourierStrategy;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;

/**
 * Кандидат для проверки правил подбора.
 *
 * Значения метрик задаются напрямую: правила только сравнивают кандидатов
 * и не считают расстояние и время подхода сами.
 */
function dispatchCandidate(
    int $courierId,
    ?float $distanceToPickupKm = 1.0,
    int $assignmentsInShift = 0,
    ?int $pickupApproachMinutes = 5,
    int $orderEtaMinutes = 30,
): CourierCandidate {
    return new CourierCandidate(
        courierId: $courierId,
        name: 'Курьер '.$courierId,
        transport: TransportType::Light,
        lightVehicleKind: LightVehicleKind::Bicycle,
        distanceToPickupKm: $distanceToPickupKm,
        assignmentsInShift: $assignmentsInShift,
        pickupApproachMinutes: $pickupApproachMinutes,
        orderEtaMinutes: $orderEtaMinutes,
    );
}

/**
 * Идентификаторы кандидатов в порядке результата правила.
 *
 * @param list<CourierCandidate> $candidates
 * @return list<int>
 */
function dispatchIds(array $candidates): array
{
    return array_map(static fn (CourierCandidate $candidate): int => $candidate->courierId(), $candidates);
}

/**
 * Набор, на котором правила дают разных победителей.
 *
 * Ближайший — второй (1.0 км), наименее загруженный — третий (0 назначений),
 * лучший ETA — первый (подход 5 + прогноз 30 против 42 и 39).
 *
 * @return list<CourierCandidate>
 */
function dispatchMixedCandidates(): array
{
    return [
        dispatchCandidate(1, distanceToPickupKm: 3.0, assignmentsInShift: 2, pickupApproachMinutes: 5),
        dispatchCandidate(2, distanceToPickupKm: 1.0, assignmentsInShift: 3, pickupApproachMinutes: 12),
        dispatchCandidate(3, distanceToPickupKm: 2.0, assignmentsInShift: 0, pickupApproachMinutes: 9),
    ];
}

/**
 * Правила подбора для проверок.
 *
 * @return array<string, CourierAssignmentStrategy>
 */
function dispatchStrategies(): array
{
    return [
        'nearest' => new NearestCourierStrategy,
        'least_loaded' => new LeastLoadedCourierStrategy,
        'best_eta' => new BestEtaCourierStrategy,
    ];
}

it('на одном наборе кандидатов правила дают разные первые места', function (): void {
    $candidates = dispatchMixedCandidates();

    expect(dispatchIds(dispatchStrategies()['nearest']->rank($candidates))[0])->toBe(2)
        ->and(dispatchIds(dispatchStrategies()['least_loaded']->rank($candidates))[0])->toBe(3)
        ->and(dispatchIds(dispatchStrategies()['best_eta']->rank($candidates))[0])->toBe(1);
});

it('не теряет и не дублирует кандидатов при упорядочивании', function (): void {
    $candidates = dispatchMixedCandidates();

    foreach (dispatchStrategies() as $strategy) {
        $ranked = $strategy->rank($candidates);

        expect($ranked)->toHaveCount(3)
            ->and(dispatchIds($ranked))->toEqualCanonicalizing([1, 2, 3]);
    }
});

it('ставит кандидата без позиции последним, но не исключает его', function (): void {
    $withoutPosition = dispatchCandidate(9, distanceToPickupKm: null, pickupApproachMinutes: null);
    $candidates = [dispatchCandidate(4, distanceToPickupKm: 4.0), $withoutPosition];

    expect(dispatchIds(dispatchStrategies()['nearest']->rank($candidates)))->toBe([4, 9])
        ->and(dispatchIds(dispatchStrategies()['best_eta']->rank($candidates)))->toBe([4, 9]);
});

it('при ничьей сравнивает загрузку, затем идентификатор курьера', function (): void {
    $candidates = [
        dispatchCandidate(7, assignmentsInShift: 2),
        dispatchCandidate(5, assignmentsInShift: 1),
        dispatchCandidate(3, assignmentsInShift: 1),
    ];

    expect(dispatchIds(dispatchStrategies()['nearest']->rank($candidates)))->toBe([3, 5, 7]);
});

it('даёт одинаковый порядок при обратном порядке входа', function (): void {
    $candidates = dispatchMixedCandidates();

    foreach (dispatchStrategies() as $strategy) {
        expect(dispatchIds($strategy->rank(array_reverse($candidates))))
            ->toBe(dispatchIds($strategy->rank($candidates)));
    }
});

it('возвращает пустой список для пустого набора', function (): void {
    foreach (dispatchStrategies() as $strategy) {
        expect($strategy->rank([]))->toBe([]);
    }
});
