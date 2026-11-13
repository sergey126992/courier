<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Оболочка полноэкранных Livewire-компонентов (план «Обновление стека», п. 5)
|--------------------------------------------------------------------------
|
| Во Livewire 4 умолчание layout-а сменилось на 'layouts::app' (файл
| resources/views/layouts/app.blade.php). Наша оболочка лежит в
| resources/views/components/layouts/app.blade.php, поэтому без явного
| значения в config/livewire.php страница отрисовалась бы без оболочки и
| сломалась бы молча. Тест держит связку: настройка → оболочка → слот.
|
*/

it('рисует полноэкранный компонент внутри оболочки приложения', function (): void {
    expect(config('livewire.component_layout'))->toBe('components.layouts.app');

    Route::livewire('/test/livewire-page', 'auth.forgot-password-form')->middleware('web');

    Route::getRoutes()->refreshNameLookups();

    $response = $this->get('/test/livewire-page')->assertOk();

    // Оболочка страницы на месте: шапка, основная область, тема.
    $response->assertSee('<header class="topbar"', false)
        ->assertSee('<main id="main"', false)
        ->assertSee('data-theme', false);

    // Содержимое компонента отрисовано в слоте, а скрипт подключает оболочка.
    $response->assertSee('wire:submit="send"', false)
        ->assertSee('window.livewireScriptConfig', false);
});
