<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blade-компоненты сообщений и индикаторов (ЧТЗ 9.3, пункт 3)
|--------------------------------------------------------------------------
|
| Проверяются role по важности сообщения, текстовая подпись индикатора
| загрузки и скрытие декоративных заглушек от вспомогательных технологий.
|
*/

it('объявляет успех статусом, а ошибку — предупреждением', function (): void {
    $success = $this->blade(
        '<x-app.alert variant="success" title="Готово">Заказ создан</x-app.alert>',
    )->__toString();

    expect($success)
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('alert-success')
        ->toContain('Готово')
        ->toContain('Заказ создан');

    $error = $this->blade(
        '<x-app.alert variant="error" title="Ошибка">Не удалось создать заказ</x-app.alert>',
    )->__toString();

    expect($error)
        ->toContain('role="alert"')
        ->toContain('aria-live="assertive"')
        ->toContain('alert-error');
});

it('показывает спиннер с ролью статуса и текстовой подписью', function (): void {
    $html = $this->blade('<x-app.spinner />')->__toString();

    expect($html)
        ->toContain('role="status"')
        ->toContain('spinner')
        ->toContain(__('ui.states.loading'));
});

it('скрывает скелетон от вспомогательных технологий', function (): void {
    $html = $this->blade('<x-app.skeleton :lines="3" />')->__toString();

    expect($html)
        ->toContain('aria-hidden="true"')
        ->and(substr_count($html, 'skeleton'))->toBe(3);
});

it('выводит тост как статус с текстом сообщения', function (): void {
    $html = $this->blade('<x-app.toast variant="success" message="Изменения сохранены" />')->__toString();

    expect($html)
        ->toContain('role="status"')
        ->toContain('data-variant="success"')
        ->toContain('border-success')
        ->toContain('Изменения сохранены');
});
