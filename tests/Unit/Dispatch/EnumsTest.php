<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\FailureReason;

it('отдаёт подписи статусов назначения', function (): void {
    expect(AssignmentStatus::Assigned->label())->toBe('Назначено')
        ->and(AssignmentStatus::Accepted->label())->toBe('Принято')
        ->and(AssignmentStatus::PickedUp->label())->toBe('Забрано')
        ->and(AssignmentStatus::Delivered->label())->toBe('Вручено')
        ->and(AssignmentStatus::Failed->label())->toBe('Не выполнено')
        ->and(AssignmentStatus::Cancelled->label())->toBe('Отменено');
});

it('делит статусы назначения на активные и терминальные', function (): void {
    expect(AssignmentStatus::Assigned->isActive())->toBeTrue()
        ->and(AssignmentStatus::Accepted->isActive())->toBeTrue()
        ->and(AssignmentStatus::PickedUp->isActive())->toBeTrue()
        ->and(AssignmentStatus::Delivered->isActive())->toBeFalse()
        ->and(AssignmentStatus::Failed->isActive())->toBeFalse()
        ->and(AssignmentStatus::Cancelled->isActive())->toBeFalse()
        ->and(AssignmentStatus::Delivered->isTerminal())->toBeTrue()
        ->and(AssignmentStatus::Accepted->isTerminal())->toBeFalse();
});

it('делит все случаи статуса ровно на активные и терминальные', function (): void {
    $active = array_values(array_filter(
        AssignmentStatus::cases(),
        static fn (AssignmentStatus $status): bool => $status->isActive(),
    ));
    $terminal = array_values(array_filter(
        AssignmentStatus::cases(),
        static fn (AssignmentStatus $status): bool => $status->isTerminal(),
    ));

    expect($active)->toBe(AssignmentStatus::active())
        ->and(count($active) + count($terminal))->toBe(count(AssignmentStatus::cases()))
        ->and(AssignmentStatus::activeValues())->toBe(['assigned', 'accepted', 'picked_up']);
});

it('отдаёт подписи правил подбора', function (): void {
    expect(AssignmentStrategy::Nearest->label())->toBe('Ближайший')
        ->and(AssignmentStrategy::LeastLoaded->label())->toBe('Наименее загруженный')
        ->and(AssignmentStrategy::BestEta->label())->toBe('Лучший прогноз прибытия');
});

it('отдаёт подписи причин неудачи', function (): void {
    expect(FailureReason::RecipientUnavailable->label())->toBe('Получатель недоступен')
        ->and(FailureReason::RecipientRefused->label())->toBe('Получатель отказался')
        ->and(FailureReason::WrongAddress->label())->toBe('Неверный адрес')
        ->and(FailureReason::AccessDenied->label())->toBe('Доступ к адресу закрыт')
        ->and(FailureReason::Other->label())->toBe('Другая причина');
});
