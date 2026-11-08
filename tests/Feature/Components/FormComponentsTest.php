<?php

declare(strict_types=1);

use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/*
|--------------------------------------------------------------------------
| Blade-компоненты форм (ЧТЗ 9.3, пункты 1–3)
|--------------------------------------------------------------------------
|
| Проверяется только представление: подпись связана с полем, ошибка стоит
| у поля и объявлена через aria-*, значения переживают повторный показ
| формы, а кнопка в загрузке блокирует повторную отправку.
| База, Actions и сеть не нужны.
|
*/

/**
 * Публикует ошибки валидации в общий набор ошибок представлений.
 *
 * @param array<string, string> $messages
 */
function shareFormErrors(array $messages): void
{
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag($messages)));
}

/**
 * Подкладывает старые значения формы в сессию запроса, чтобы работал old().
 *
 * @param array<string, string> $input
 */
function rememberFormOldInput(array $input): void
{
    $session = app(SessionManager::class)->driver();
    $session->start();
    $session->put('_old_input', $input);
    request()->setLaravelSession($session);
}

it('связывает подпись, подсказку и поле', function (): void {
    $html = $this->blade(
        '<x-app.field name="email" label="Email" hint="Адрес для писем"><x-app.input name="email" type="email" /></x-app.field>',
    )->__toString();

    expect($html)
        ->toContain('<label')
        ->toContain('for="email"')
        ->toContain('id="email"')
        ->toContain('aria-describedby="email-hint"')
        ->toContain('Адрес для писем');
});

it('выводит ошибку у поля и помечает его невалидным', function (): void {
    shareFormErrors(['email' => 'Некорректный адрес почты']);

    $html = $this->blade(
        '<x-app.field name="email" label="Email"><x-app.input name="email" type="email" /></x-app.field>',
    )->__toString();

    expect($html)
        ->toContain('aria-invalid="true"')
        ->toContain('id="email-error"')
        ->toContain('aria-describedby="email-error"')
        ->toContain('Некорректный адрес почты');
});

it('блокирует кнопку в состоянии загрузки', function (): void {
    $html = $this->blade('<x-app.button loading>Отправить</x-app.button>')->__toString();

    expect($html)
        ->toContain('type="button"')
        ->toContain('disabled')
        ->toContain('aria-busy="true"')
        ->toContain('btn-primary')
        ->toContain('Отправить');
});

it('применяет вариант и размер кнопки', function (): void {
    $html = $this->blade('<x-app.button variant="danger" size="sm" type="submit">Удалить</x-app.button>')->__toString();

    expect($html)
        ->toContain('btn-danger')
        ->toContain('btn-sm')
        ->toContain('type="submit"');

    expect(str_contains($html, 'aria-busy'))->toBeFalse();
});

it('восстанавливает значение поля из old()', function (): void {
    rememberFormOldInput(['email' => 'saved@example.com']);

    $html = $this->blade('<x-app.input name="email" type="email" />')->__toString();

    expect($html)->toContain('value="saved@example.com"');
});

it('восстанавливает выбранную опцию селекта из old()', function (): void {
    rememberFormOldInput(['status' => 'assigned']);

    $html = $this->blade('<x-app.select name="status" :options="$options" />', [
        'options' => ['created' => 'Создан', 'assigned' => 'Назначен'],
    ])->__toString();

    expect($html)->toMatch('/<option value="assigned"\s+selected/');
});

it('восстанавливает значение многострочного поля из old()', function (): void {
    rememberFormOldInput(['comment' => 'Позвонить за час']);

    $html = $this->blade('<x-app.textarea name="comment" rows="3" />')->__toString();

    expect($html)
        ->toContain('Позвонить за час')
        ->toContain('rows="3"');
});

it('связывает чекбокс с подписью и восстанавливает отметку', function (): void {
    rememberFormOldInput(['remember' => '1']);

    $html = $this->blade('<x-app.checkbox name="remember" value="1" label="Запомнить меня" />')->__toString();

    expect($html)
        ->toContain('type="checkbox"')
        ->toContain('for="remember"')
        ->toContain('id="remember"')
        ->toContain('checked')
        ->toContain('Запомнить меня');
});

it('растягивает зону нажатия чекбокса до 44 px', function (): void {
    $html = $this->blade('<x-app.checkbox name="remember" value="1" label="Запомнить меня" />')->__toString();

    // FR-UI-410: подпись кликабельна и не ниже 44 px, сам квадрат остаётся 20×20.
    expect($html)->toContain('checkbox min-h-11 items-center');
    expect($html)->not->toContain('checkbox min-h-6');
});

it('добавляет target и rel внешней ссылке и не трогает внутреннюю', function (): void {
    $external = $this->blade('<x-app.link href="https://example.com/rules">Правила</x-app.link>')->__toString();
    $internal = $this->blade('<x-app.link href="/cabinet">Кабинет</x-app.link>')->__toString();

    expect($external)
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"');

    expect(str_contains($internal, 'target='))->toBeFalse()
        ->and(str_contains($internal, 'rel='))->toBeFalse();
});
