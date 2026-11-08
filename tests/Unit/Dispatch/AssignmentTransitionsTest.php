<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentTransitions;
use App\Domain\Dispatch\Exceptions\AssignmentTransitionNotAllowed;

it('разрешает переходы этапов назначения', function (AssignmentStatus $from, AssignmentStatus $to): void {
    expect(AssignmentTransitions::isAllowed($from, $to))->toBeTrue();

    AssignmentTransitions::assertAllowed($from, $to);

    expect(AssignmentTransitions::targets($from))->toContain($to);
})->with([
    [AssignmentStatus::Assigned, AssignmentStatus::Accepted],
    [AssignmentStatus::Assigned, AssignmentStatus::Cancelled],
    [AssignmentStatus::Accepted, AssignmentStatus::PickedUp],
    [AssignmentStatus::Accepted, AssignmentStatus::Cancelled],
    [AssignmentStatus::PickedUp, AssignmentStatus::Delivered],
    [AssignmentStatus::PickedUp, AssignmentStatus::Failed],
]);

it('запрещает переходы вне таблицы', function (AssignmentStatus $from, AssignmentStatus $to): void {
    expect(AssignmentTransitions::isAllowed($from, $to))->toBeFalse();

    expect(static fn () => AssignmentTransitions::assertAllowed($from, $to))
        ->toThrow(AssignmentTransitionNotAllowed::class);
})->with([
    [AssignmentStatus::Assigned, AssignmentStatus::PickedUp],
    [AssignmentStatus::Assigned, AssignmentStatus::Delivered],
    [AssignmentStatus::Accepted, AssignmentStatus::Delivered],
    [AssignmentStatus::PickedUp, AssignmentStatus::Cancelled],
    [AssignmentStatus::Delivered, AssignmentStatus::Cancelled],
    [AssignmentStatus::Failed, AssignmentStatus::Assigned],
    [AssignmentStatus::Cancelled, AssignmentStatus::Assigned],
]);

it('не имеет переходов из терминальных статусов', function (AssignmentStatus $terminal): void {
    expect($terminal->isTerminal())->toBeTrue()
        ->and(AssignmentTransitions::targets($terminal))->toBe([]);
})->with([
    [AssignmentStatus::Delivered],
    [AssignmentStatus::Failed],
    [AssignmentStatus::Cancelled],
]);

it('считает активными только назначено, принято и забрано', function (): void {
    expect(AssignmentStatus::active())->toBe([
        AssignmentStatus::Assigned,
        AssignmentStatus::Accepted,
        AssignmentStatus::PickedUp,
    ]);
});

it('несёт статусы в исключении', function (): void {
    try {
        AssignmentTransitions::assertAllowed(AssignmentStatus::Delivered, AssignmentStatus::Assigned);
    } catch (AssignmentTransitionNotAllowed $exception) {
        expect($exception->fromStatus())->toBe(AssignmentStatus::Delivered)
            ->and($exception->toStatus())->toBe(AssignmentStatus::Assigned);

        return;
    }

    $this->fail('Ожидалось исключение AssignmentTransitionNotAllowed.');
});
