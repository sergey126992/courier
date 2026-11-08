<?php

declare(strict_types=1);

it('отвечает на корневой запрос без данных окружения', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertExactJson(['service' => 'notifications']);
});

it('сообщает о живости процесса', function (): void {
    $this->get('/up')->assertOk();
});
