# ForgotPasswordForm

## Назначение
Форма запроса ссылки сброса пароля в браузере: ставит письмо в очередь операцией
`App\Actions\Identity\SendPasswordResetLink` и показывает нейтральное сообщение. Ответ не зависит
от существования аккаунта. Закрывает FR-UI-107 и контракт `auth.forgot-password-form` (ЧТЗ 8.2).

## Расположение и вызов
- Код: `app/Livewire/Auth/ForgotPasswordForm.php`, шаблон `resources/views/livewire/auth/forgot-password-form.blade.php`
- Вызов: `<livewire:auth.forgot-password-form />` со страницы `resources/views/auth/forgot-password.blade.php`
  (маршрут `password.request`, GET `/forgot-password`)

## Входные параметры и слоты
Livewire-компонент без параметров; состояние — свойства:
- `email` (`string`): адрес; приводится к нижнему регистру перед отправкой.
- `sent` (`bool`): после успешного запроса форма заменяется сообщением `auth.password_reset_sent`.

## Поведение и состояния
- `send()`: валидация набором `App\Http\Requests\Auth\AuthRules::passwordResetRequest()` (общий
  с API-запросом `ForgotPasswordRequest`) → `SendPasswordResetLink` с маршрутом
  `config('ui.links.password_reset_route')` → `sent = true`.
- Существующий и несуществующий адрес дают одинаковый текст и одинаковое состояние.
- Состояния: форма, отправка (`wire:loading`), сообщение об отправке; ошибка формата email — у поля.
- Клавиатура и фокус: нативные `label`/`input`, ссылка «Вернуться на страницу входа» доступна с клавиатуры.

## Ограничения
- Не проверяет, есть ли аккаунт: это решает операция, а ответ всегда нейтральный.
- Не создаёт токен и не строит ссылку вручную: подпись и срок — в `ResetPasswordMail`.
- Не ограничивает частоту запросов: лимит `auth-password-forgot` стоит на маршруте `password.email`.

## Где используется
- `resources/views/auth/forgot-password.blade.php` — страница маршрута `password.request`.
- Ссылка «Забыли пароль?» на странице входа `resources/views/auth/login.blade.php`.
- Та же операция у POST-маршрута `password.email` (`App\Http\Controllers\Web\Auth\ForgotPasswordController`).

## Какие тесты покрывают
- `tests/Feature/Livewire/Auth/PasswordRecoveryTest.php` — одинаковый ответ, ссылка на web-маршрут сброса.
- `tests/Feature/Web/PasswordResetWebTest.php` — форма запроса, POST без Livewire, одинаковый ответ, лимит.
