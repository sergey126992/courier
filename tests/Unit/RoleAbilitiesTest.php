<?php

declare(strict_types=1);

use App\Domain\Identity\Role;

it('выдаёт отправителю чтение и запись заказов', function (): void {
    expect(Role::Sender->abilities())->toBe(['orders:read', 'orders:write']);
});

it('выдаёт курьеру чтение заказов и действия курьера', function (): void {
    expect(Role::Courier->abilities())->toBe(['orders:read', 'courier']);
});

it('выдаёт диспетчеру чтение заказов и операционные действия', function (): void {
    expect(Role::Dispatcher->abilities())->toBe(['orders:read', 'dispatch']);
});

it('оставляет администратору чтение заказов и право отчёта', function (): void {
    expect(Role::Admin->abilities())->toBe(['orders:read', 'reports:read']);
});

it('выдаёт право отчёта только администратору', function (): void {
    $roles = array_values(array_filter(
        Role::cases(),
        static fn (Role $role): bool => $role->canViewReports(),
    ));

    expect(array_map(static fn (Role $role): string => $role->value, $roles))->toBe(['admin']);
});

it('не выдаёт reports:read остальным ролям', function (): void {
    foreach ([Role::Sender, Role::Courier, Role::Dispatcher] as $role) {
        expect($role->abilities())->not->toContain('reports:read');
    }
});
