<?php

declare(strict_types=1);

use App\Domain\Identity\Role;

it('содержит все роли этапа', function (): void {
    $roles = array_map(
        static fn (Role $role): string => $role->value,
        Role::cases(),
    );

    expect($roles)->toBe(['sender', 'courier', 'dispatcher', 'admin']);
});

it('выдаёт название роли по значению', function (string $value, string $label): void {
    expect(Role::from($value)->label())->toBe($label);
})->with([
    ['sender', 'Отправитель'],
    ['courier', 'Курьер'],
    ['dispatcher', 'Диспетчер'],
    ['admin', 'Администратор'],
]);

it('не содержит пустых названий', function (): void {
    foreach (Role::cases() as $role) {
        expect(trim($role->label()))->not->toBe('');
    }
});
