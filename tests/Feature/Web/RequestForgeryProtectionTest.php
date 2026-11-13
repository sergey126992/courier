<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Защита от подделки запросов (Laravel 13, PreventRequestForgery)
|--------------------------------------------------------------------------
|
| Laravel 13 переименовал CSRF-middleware в PreventRequestForgery и добавил
| сверку origin по заголовку Sec-Fetch-Site. В тестовом окружении фреймворк
| пропускает эту проверку, поэтому на время сценария окружение переводится
| в `local`: только так видно поведение, которое получит браузер.
|
*/

/**
 * Отключает тестовое исключение PreventRequestForgery.
 *
 * Application::runningUnitTests() проверяет значение `env`, поэтому смена
 * окружения на `local` возвращает middleware в рабочий режим. Каждый тест
 * получает свежее приложение, поэтому восстановление не требуется.
 */
function actAsBrowserRequest(): void
{
    app()->detectEnvironment(static fn (): string => 'local');
}

it('отклоняет изменение состояния без токена', function (): void {
    actAsBrowserRequest();

    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'secret-pass-1'])
        ->assertStatus(419);
});

it('отклоняет запрос с чужого origin без токена', function (): void {
    actAsBrowserRequest();

    $this->withHeader('Sec-Fetch-Site', 'cross-site')
        ->post('/login', ['email' => 'nobody@example.com', 'password' => 'secret-pass-1'])
        ->assertStatus(419);
});

it('пропускает запрос со своего origin без токена', function (): void {
    actAsBrowserRequest();

    // Дошло до проверки учётных данных: отказ входа — это редирект, а не 419.
    $this->withHeader('Sec-Fetch-Site', 'same-origin')
        ->post('/login', ['email' => 'nobody@example.com', 'password' => 'secret-pass-1'])
        ->assertStatus(302);
});

it('пропускает запрос с верным токеном даже с чужого origin', function (): void {
    actAsBrowserRequest();

    $this->withSession(['_token' => 'csrf-token-value'])
        ->withHeader('Sec-Fetch-Site', 'cross-site')
        ->post('/login', [
            '_token' => 'csrf-token-value',
            'email' => 'nobody@example.com',
            'password' => 'secret-pass-1',
        ])
        ->assertStatus(302);
});

it('выдаёт XSRF-TOKEN cookie вместе с формой входа', function (): void {
    actAsBrowserRequest();

    $this->get('/login')->assertOk()->assertCookie('XSRF-TOKEN');
});
