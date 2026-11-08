<?php

declare(strict_types=1);

it('запускает потребителя с ключом --once на пустом потоке', function (): void {
    fakeEventStream();

    $this->artisan('notifications:consume', ['--once' => true])
        ->assertExitCode(0);
});

it('запускает каркас поллера отправки с ключом --once', function (): void {
    $this->artisan('notifications:send', ['--once' => true])
        ->assertExitCode(0);
});

it('выводит результат прохода потребителя в JSON по ключу --json', function (): void {
    fakeEventStream();

    $this->artisan('notifications:consume', ['--once' => true, '--json' => true])
        ->expectsOutputToContain('"status":"ok"')
        ->assertExitCode(0);
});
