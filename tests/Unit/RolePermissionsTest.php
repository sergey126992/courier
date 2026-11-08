<?php

declare(strict_types=1);

use App\Domain\Identity\Role;

it('раздаёт права по роли', function (
    Role $role,
    bool $createOrders,
    bool $dispatch,
    bool $assignments,
    bool $references,
): void {
    expect($role->canCreateOrders())->toBe($createOrders)
        ->and($role->canDispatch())->toBe($dispatch)
        ->and($role->canHandleAssignments())->toBe($assignments)
        ->and($role->canManageReferences())->toBe($references);
})->with([
    'отправитель' => [Role::Sender, true, false, false, false],
    'курьер' => [Role::Courier, false, false, true, false],
    'диспетчер' => [Role::Dispatcher, false, true, false, false],
    'администратор' => [Role::Admin, false, false, false, true],
]);

it('разрешает править собственный профиль только отправителю и курьеру', function (): void {
    $editable = array_values(array_filter(
        Role::cases(),
        static fn (Role $role): bool => $role->canEditOwnProfile(),
    ));

    expect(array_map(static fn (Role $role): string => $role->value, $editable))
        ->toBe(['sender', 'courier']);
});

it('признаёт профиль отправителя только у роли «отправитель»', function (): void {
    expect(Role::Sender->hasSenderProfile())->toBeTrue()
        ->and(Role::Courier->hasSenderProfile())->toBeFalse()
        ->and(Role::Dispatcher->hasSenderProfile())->toBeFalse()
        ->and(Role::Admin->hasSenderProfile())->toBeFalse();
});
