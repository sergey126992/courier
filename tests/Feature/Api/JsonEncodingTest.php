<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Кодирование JSON в ответах API
|--------------------------------------------------------------------------
|
| Флаги задаются в одном месте (App\Http\ApiResponse) и одинаковы для успешных
| ответов и ошибок, поэтому кириллица не превращается в \uXXXX.
|
*/

it('отдаёт кириллицу без экранирования в успешном входе', function (): void {
    Mail::fake();

    User::factory()->sender()->create([
        'name' => 'Иван Отправитель',
        'email' => 'json-login@example.com',
        'password' => 'secret-pass-1',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'json-login@example.com',
        'password' => 'secret-pass-1',
    ]);

    $response->assertOk();

    $body = (string) $response->getContent();

    expect($body)->not->toContain('\u0418')
        ->and($body)->toContain('Иван Отправитель');
});

it('отдаёт кириллицу без экранирования в сообщениях сброса пароля', function (): void {
    Mail::fake();

    User::factory()->create(['email' => 'json-reset@example.com']);

    $forgot = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'json-reset@example.com']);

    $forgot->assertStatus(202);

    $body = (string) $forgot->getContent();

    expect($body)->not->toContain('\u0415')
        ->and($body)->toContain('Если такой адрес зарегистрирован');
});

it('отдаёт кириллицу без экранирования в ошибках', function (): void {
    $response = $this->postJson('/api/v1/auth/register', []);

    $response->assertStatus(422);

    $body = (string) $response->getContent();

    expect($body)->not->toContain('\u041e')
        ->and($body)->toContain('Ошибка валидации');
});
