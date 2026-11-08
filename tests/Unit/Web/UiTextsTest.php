<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Тексты интерфейса (FR-UI-197)
|--------------------------------------------------------------------------
|
| Каждый ключ, который понадобится экранам M9, обязан существовать и
| возвращать непустую строку, а не имя ключа. Тест не требует базы.
|
*/

beforeEach(function (): void {
    app()->setLocale('ru');
});

it('содержит подпись интерфейса', function (string $key): void {
    $text = (string) __($key);

    expect($text)->not->toBe('')
        ->and($text)->not->toBe($key)
        ->and($text)->not->toContain($key);
})->with([
    'бренд: название' => 'ui.brand.name',
    'бренд: короткое имя' => 'ui.brand.short',
    'бренд: описание' => 'ui.brand.tagline',

    'навигация: подпись' => 'ui.nav.label',
    'навигация: главная' => 'ui.nav.home',
    'навигация: заказы' => 'ui.nav.orders',
    'навигация: новый заказ' => 'ui.nav.create_order',
    'навигация: очередь' => 'ui.nav.queue',
    'навигация: профиль' => 'ui.nav.account',
    'навигация: вход' => 'ui.nav.login',
    'навигация: регистрация' => 'ui.nav.register',
    'навигация: выход' => 'ui.nav.logout',
    'навигация: открыть меню' => 'ui.nav.open_menu',
    'навигация: закрыть меню' => 'ui.nav.close_menu',

    'тема: подпись' => 'ui.theme.label',
    'тема: светлая' => 'ui.theme.light',
    'тема: тёмная' => 'ui.theme.dark',
    'тема: как в системе' => 'ui.theme.system',

    'действие: сохранить' => 'ui.actions.save',
    'действие: отмена' => 'ui.actions.cancel',
    'действие: подтвердить' => 'ui.actions.confirm',
    'действие: назад' => 'ui.actions.back',
    'действие: на главную' => 'ui.actions.back_home',
    'действие: в кабинет' => 'ui.actions.back_to_cabinet',
    'действие: на страницу входа' => 'ui.actions.back_to_login',
    'действие: повторить' => 'ui.actions.retry',
    'действие: обновить' => 'ui.actions.refresh',
    'действие: показать ещё' => 'ui.actions.show_more',
    'действие: показать кандидатов' => 'ui.actions.show_candidates',
    'действие: назначить' => 'ui.actions.assign',
    'действие: переназначить' => 'ui.actions.reassign',
    'действие: отменить заказ' => 'ui.actions.cancel_order',
    'действие: применить фильтры' => 'ui.actions.apply_filters',
    'действие: сбросить фильтры' => 'ui.actions.reset_filters',
    'действие: рассчитать' => 'ui.actions.calculate',
    'действие: создать заказ' => 'ui.actions.create_order',
    'действие: скопировать' => 'ui.actions.copy',
    'действие: создать ссылку' => 'ui.actions.create_tracking_link',
    'действие: отправить письмо ещё раз' => 'ui.actions.resend_verification',
    'действие: приостановить обновление' => 'ui.actions.pause_refresh',
    'действие: возобновить обновление' => 'ui.actions.resume_refresh',

    'состояние: загрузка' => 'ui.states.loading',
    'состояние: заказов нет' => 'ui.states.empty_orders_title',
    'состояние: пояснение пустых заказов' => 'ui.states.empty_orders_description',
    'состояние: ничего не найдено' => 'ui.states.empty_filtered_title',
    'состояние: пояснение фильтров' => 'ui.states.empty_filtered_description',
    'состояние: очередь пуста' => 'ui.states.empty_queue_title',
    'состояние: пояснение пустой очереди' => 'ui.states.empty_queue_description',
    'состояние: курьеров нет' => 'ui.states.empty_candidates_title',
    'состояние: пояснение без курьеров' => 'ui.states.empty_candidates_description',
    'состояние: событий нет' => 'ui.states.empty_tracking_title',
    'состояние: пояснение событий' => 'ui.states.empty_tracking_description',

    'фильтр: подпись' => 'ui.filters.title',
    'фильтр: статус' => 'ui.filters.status',
    'фильтр: период с' => 'ui.filters.period_from',
    'фильтр: период по' => 'ui.filters.period_to',
    'фильтр: зона' => 'ui.filters.zone',
    'фильтр: транспорт' => 'ui.filters.transport',

    'форма: вход' => 'ui.forms.login_title',
    'форма: регистрация' => 'ui.forms.register_title',
    'форма: забыли пароль' => 'ui.forms.forgot_password_title',
    'форма: новый пароль' => 'ui.forms.reset_password_title',
    'форма: email' => 'ui.forms.email',
    'форма: пароль' => 'ui.forms.password',
    'форма: повтор пароля' => 'ui.forms.password_confirmation',
    'форма: имя' => 'ui.forms.name',
    'форма: запомнить меня' => 'ui.forms.remember',
    'форма: ссылка на сброс' => 'ui.forms.forgot_password',
    'форма: кнопка входа' => 'ui.forms.submit_login',
    'форма: кнопка регистрации' => 'ui.forms.submit_register',
    'форма: кнопка запроса ссылки' => 'ui.forms.submit_forgot_password',
    'форма: кнопка сохранения пароля' => 'ui.forms.submit_reset_password',

    'форма: адрес откуда забрать' => 'ui.create_order.pickup',
    'форма: адрес доставки' => 'ui.create_order.dropoff',

    'адрес: подсказка в поле' => 'ui.create_order.address_placeholder',
    'адрес: пояснение выбора' => 'ui.create_order.address_hint',
    'адрес: список подсказок' => 'ui.create_order.address_options',
    'адрес: ничего не найдено' => 'ui.create_order.addresses_empty',
    'адрес: очистить адрес' => 'ui.create_order.address_clear',
    'адрес: адрес не выбран' => 'ui.create_order.address_not_selected',

    'лендинг: заголовок' => 'ui.landing.title',
    'лендинг: описание' => 'ui.landing.description',
    'лендинг: вход' => 'ui.landing.login',
    'лендинг: регистрация' => 'ui.landing.register',

    'профиль: заголовок' => 'ui.profile.title',
    'профиль: имя' => 'ui.profile.name',
    'профиль: email' => 'ui.profile.email',
    'профиль: роль' => 'ui.profile.role',
    'профиль: статус почты' => 'ui.profile.email_status',
    'профиль: почта подтверждена' => 'ui.profile.email_verified',
    'профиль: почта не подтверждена' => 'ui.profile.email_unverified',
    'профиль: будущие экраны' => 'ui.profile.future_screens',

    'почта: заголовок' => 'ui.verification.title',
    'почта: напоминание' => 'ui.verification.notice',
    'почта: подтверждена' => 'ui.verification.verified_title',
    'почта: текст подтверждения' => 'ui.verification.verified',
    'почта: уже подтверждена' => 'ui.verification.already_verified_title',
    'почта: текст повторного подтверждения' => 'ui.verification.already_verified',
    'почта: письмо отправлено' => 'ui.verification.sent',

    'подтверждение: отменить заказ' => 'ui.confirm.cancel_order',
    'подтверждение: пояснение отмены' => 'ui.confirm.cancel_order_description',
    'подтверждение: назначить курьера' => 'ui.confirm.assign_courier',
    'подтверждение: переназначить курьера' => 'ui.confirm.reassign_courier',

    'ошибка 403: заголовок' => 'ui.errors.403.title',
    'ошибка 403: пояснение' => 'ui.errors.403.description',
    'ошибка 404: заголовок' => 'ui.errors.404.title',
    'ошибка 404: пояснение' => 'ui.errors.404.description',
    'ошибка 419: заголовок' => 'ui.errors.419.title',
    'ошибка 419: пояснение' => 'ui.errors.419.description',
    'ошибка 429: заголовок' => 'ui.errors.429.title',
    'ошибка 429: пояснение' => 'ui.errors.429.description',
    'ошибка 500: заголовок' => 'ui.errors.500.title',
    'ошибка 500: пояснение' => 'ui.errors.500.description',

    'формат: дата' => 'ui.formats.date',
    'формат: дата и время' => 'ui.formats.datetime',
    'формат: время' => 'ui.formats.time',
    'формат: деньги' => 'ui.formats.money',
    'формат: десятичный разделитель' => 'ui.formats.money_decimal_separator',
    'формат: разделитель тысяч' => 'ui.formats.money_thousands_separator',
]);

it('держит подписи действий из требований', function (): void {
    expect(__('ui.actions.show_more'))->toBe('Показать ещё')
        ->and(__('ui.actions.refresh'))->toBe('Обновить')
        ->and(__('ui.actions.assign'))->toBe('Назначить')
        ->and(__('ui.actions.reassign'))->toBe('Переназначить')
        ->and(__('ui.states.empty_candidates_title'))->toBe('Курьеров нет');
});

it('содержит сообщения веб-сценариев в соседних файлах', function (string $key): void {
    $text = (string) __($key);

    expect($text)->not->toBe('')
        ->and($text)->not->toBe($key);
})->with([
    'auth: требуется подтверждение' => 'auth.verification_required',
    'orders: заказ создан' => 'orders.messages.created',
    'orders: заказ отменён' => 'orders.messages.cancelled',
    'orders: конфликт отмены' => 'orders.messages.cancel_conflict',
    'orders: подпись номера' => 'orders.labels.number',
    'dispatch: курьер назначен' => 'dispatch.messages.assigned',
    'dispatch: курьер переназначен' => 'dispatch.messages.reassigned',
    'dispatch: правило подбора' => 'dispatch.labels.strategy',
    'tracking: публичный заголовок' => 'tracking.labels.public_title',
    'tracking: источник данных' => 'tracking.labels.source',
    'errors: неверные учётные данные' => 'errors.web.INVALID_CREDENTIALS',
    'errors: нет курьеров' => 'errors.web.NO_COURIER_AVAILABLE',
]);
