<?php

declare(strict_types=1);

use App\Models\User;

it('скрывает пароль при сериализации', function (): void {
    $user = new User([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'password' => 'secret',
    ]);

    $array = $user->toArray();

    expect($array)->not->toHaveKey('password');
    expect($array)->not->toHaveKey('remember_token');
    expect($array)->toHaveKey('email');
});

it('приводит пароль к хешу и дату подтверждения к дате', function (): void {
    $user = new User([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'password' => 'secret',
    ]);
    $user->email_verified_at = '2026-01-01 10:00:00';

    expect($user->password)->not->toBe('secret');
    expect($user->email_verified_at)->toBeInstanceOf(DateTimeInterface::class);
});
