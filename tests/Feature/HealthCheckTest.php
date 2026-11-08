<?php

declare(strict_types=1);

it('отвечает успехом на служебном адресе', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertSee('Application up');
});
