<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Страницы ошибок веба (ЧТЗ 9.1, пункт 16; FR-UI-113, FR-UI-119, 8.4)
|--------------------------------------------------------------------------
|
| Веб-ошибки отдаются HTML-страницами resources/views/errors/*: отказ по
| роли, чужой и несуществующий объект, истёкшая сессия, превышение частоты
| и необработанное исключение. Формат problem+json остаётся у API.
|
*/

/**
 * Проверяет статус и HTML-тип ответа: JSON-ошибка на веб-запрос — нарушение.
 *
 * @param TestResponse<Response> $response
 */
function expectHtmlErrorPage(TestResponse $response, int $status): void
{
    $response->assertStatus($status);

    $contentType = (string) $response->headers->get('Content-Type');

    expect($contentType)->toStartWith('text/html')
        ->and($contentType)->not->toContain('application/problem+json');
}

it('отдаёт HTML-страницу 403 при отказе по роли', function (): void {
    Route::middleware(['web', 'auth', 'can:create,App\Models\Order\DeliveryOrder'])
        ->get('/test/orders', static fn (): string => '')
        ->name('test.orders');

    Route::getRoutes()->refreshNameLookups();

    $courier = User::factory()->courier()->create();

    $response = $this->actingAs($courier)->get('/test/orders');

    expectHtmlErrorPage($response, 403);

    $response->assertSee(__('ui.errors.403.title'))
        ->assertSee(__('ui.errors.403.description'))
        ->assertSee(__('ui.actions.back_to_cabinet'))
        ->assertSee(route('account'), false);
});

it('отдаёт одинаковую страницу 404 для чужого и несуществующего объекта', function (): void {
    Route::middleware(['web', 'auth'])
        ->get('/test/orders/{order}', static function (): never {
            // Так поведёт себя веб-карточка заказа: чужой объект неотличим от отсутствующего.
            abort(404);
        });

    Route::middleware(['web', 'auth'])
        ->get('/test/users/{user}', static fn (User $user): string => (string) $user->getKey());

    Route::getRoutes()->refreshNameLookups();

    $owner = User::factory()->sender()->create();
    $visitor = User::factory()->courier()->create();

    $foreign = $this->actingAs($visitor)->get('/test/orders/'.(string) $owner->getKey());

    // Livewire 4 отдаёт блок @livewireStyles только один раз на экземпляр
    // приложения, а тест переиспользует его. Сбрасываем состояние так же,
    // как это происходит на новом HTTP-запросе в рабочем окружении.
    Livewire::flushState();

    $missing = $this->actingAs($visitor)->get('/test/users/999999999');

    expectHtmlErrorPage($foreign, 404);
    expectHtmlErrorPage($missing, 404);

    $foreign->assertSee(__('ui.errors.404.title'))
        ->assertSee(__('ui.errors.404.description'))
        ->assertDontSee('999999999');

    // Одинаковый статус и одинаковый текст: наличие объекта не раскрывается.
    expect($foreign->getContent())->toBe($missing->getContent());
});

it('отдаёт страницу 419 с возвратом на страницу входа', function (): void {
    Route::middleware('web')->get('/test/expired', static function (): never {
        throw new TokenMismatchException;
    });

    $response = $this->get('/test/expired');

    expectHtmlErrorPage($response, 419);

    $response->assertSee(__('ui.errors.419.title'))
        ->assertSee(__('ui.errors.419.description'))
        ->assertSee(__('ui.actions.back_to_login'))
        ->assertSee(route('login'), false);
});

it('отдаёт HTML-страницу 429 при превышении частоты', function (): void {
    Route::middleware(['web', 'throttle:1,1'])
        ->get('/test/limited', static fn (): string => 'ok');

    $this->get('/test/limited')->assertOk();

    $response = $this->get('/test/limited');

    expectHtmlErrorPage($response, 429);

    $response->assertSee(__('ui.errors.429.title'));
});

it('отдаёт страницу 500 без деталей исключения', function (): void {
    config(['app.debug' => false]);

    Route::get('/test/boom', static function (): never {
        throw new RuntimeException('internal-detail-must-not-leak');
    });

    $response = $this->get('/test/boom');

    expectHtmlErrorPage($response, 500);

    $response->assertSee(__('ui.errors.500.title'))
        ->assertSee(__('ui.errors.500.description'))
        ->assertDontSee('internal-detail-must-not-leak')
        ->assertDontSee('RuntimeException')
        ->assertDontSee('Stack trace');
});

it('не отдаёт JSON-формат ошибки на несуществующий адрес', function (): void {
    $response = $this->get('/nonexistent-page');

    expectHtmlErrorPage($response, 404);

    $response->assertSee(__('ui.errors.404.title'))
        ->assertSee(__('ui.actions.back_home'));
});
