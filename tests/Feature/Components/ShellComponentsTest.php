<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Оболочка: layout, шапка, навигация, тема и модальные окна (ЧТЗ 9.3)
|--------------------------------------------------------------------------
|
| FR-UI-114, 116, 155, 173: документ собирается одним layout, тема ставится
| сервером до первой отрисовки, пункт меню недоступной роли не выводится,
| модальное окно объявляется диалогом со связанным заголовком. Утверждения
| скалярные: сравниваются отдельные строки разметки, а не снимки.
|
*/

/**
 * Регистрирует временные маршруты навигации: страницы появятся в следующих
 * пунктах плана, а шапке нужны реальные имена маршрутов из ЧТЗ 8.1.
 */
function registerShellNavRoutes(): void
{
    Route::middleware('web')->group(function (): void {
        Route::get('/cabinet/orders', static fn (): string => '')->name('cabinet.orders');
        Route::get('/cabinet/orders/create', static fn (): string => '')->name('cabinet.orders.create');
        Route::get('/dispatcher', static fn (): string => '')->name('dispatcher.queue');
        Route::get('/account', static fn (): string => '')->name('account');
        Route::post('/logout', static fn (): string => '')->name('logout');
    });

    // Маршруты добавлены после загрузки приложения: обновляем поиск по именам.
    Route::getRoutes()->refreshNameLookups();
}

/**
 * Пользователь без обращения к базе: шапке нужны только имя и роль.
 */
function shellUser(Role $role, string $name): User
{
    $user = new User(['name' => $name, 'email' => mb_strtolower($role->value).'@example.test']);
    $user->role = $role;

    return $user;
}

it('выводит модальное окно с ролью диалога и связанным заголовком', function (): void {
    $html = $this->blade(
        '<x-app.modal name="cancel-order" title="Отменить заказ?">Текст подтверждения</x-app.modal>',
    )->__toString();

    expect($html)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="cancel-order-title"')
        ->toContain('id="cancel-order-title"')
        ->toContain('Отменить заказ?')
        ->toContain('Текст подтверждения');
});

it('выводит окно подтверждения поверх модального слоя', function (): void {
    $html = $this->blade(
        '<x-app.confirm-dialog name="cancel" title="Отменить?" confirm-label="Да, отменить" />',
    )->__toString();

    expect($html)
        ->toContain('role="dialog"')
        ->toContain('modal-confirm')
        ->toContain('Да, отменить')
        ->toContain('Отмена');
});

it('ставит data-theme из cookie до отрисовки содержимого', function (): void {
    request()->cookies->set((string) config('ui.theme.cookie'), 'dark');

    $html = $this->blade('<x-layouts.app title="Страница">Содержимое</x-layouts.app>')->__toString();

    expect($html)
        ->toContain('data-theme="dark"')
        ->toContain('<html')
        ->toContain('Содержимое');
});

it('берёт значение темы по умолчанию, когда cookie нет', function (): void {
    request()->cookies->remove((string) config('ui.theme.cookie'));

    $html = $this->blade('<x-layouts.app>Содержимое</x-layouts.app>')->__toString();

    expect($html)
        ->toContain('data-theme="light"')
        ->toContain('prefers-color-scheme: dark');
});

it('не дублирует документ и подключение Vite вне layout', function (): void {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (str_contains($path, '/views/preview/')
            || str_contains($path, '/views/mail/')
            || str_ends_with($path, '/components/layouts/app.blade.php')
            || $file->getFilename() === 'welcome.blade.php') {
            continue;
        }

        $contents = $file->getContents();

        if (str_contains($contents, '<!DOCTYPE html') || str_contains($contents, '@vite')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('показывает отправителю только доступные ему пункты меню', function (): void {
    registerShellNavRoutes();

    $html = $this->blade(
        '<x-app.header :user="$user" />',
        ['user' => shellUser(Role::Sender, 'Анна Смирнова')],
    )->__toString();

    expect($html)
        ->toContain('Мои заказы')
        ->toContain('Новый заказ')
        ->toContain('Профиль')
        ->toContain('Выйти');

    expect($html)->not->toContain('Очередь заказов');
});

it('скрывает от диспетчера пункты отправителя и показывает очередь', function (): void {
    registerShellNavRoutes();

    $html = $this->blade(
        '<x-app.header :user="$user" />',
        ['user' => shellUser(Role::Dispatcher, 'Пётр Диспетчеров')],
    )->__toString();

    expect($html)
        ->toContain('Очередь заказов')
        ->toContain('Профиль');

    expect($html)->not->toContain('Мои заказы');
    expect($html)->not->toContain('Новый заказ');
});

it('помечает выбранное значение переключателя темы через aria-pressed', function (): void {
    $html = $this->blade('<x-app.theme-toggle value="dark" />')->__toString();

    expect($html)
        ->toContain('role="group"')
        ->toContain('aria-pressed="true"')
        ->toContain('data-theme-value="light"')
        ->toContain('data-theme-value="dark"')
        ->toContain('data-theme-value="system"')
        ->toContain('Светлая')
        ->toContain('Тёмная')
        ->toContain('Как в системе');
});
