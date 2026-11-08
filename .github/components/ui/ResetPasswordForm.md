# ResetPasswordForm

## Назначение
Форма установки нового пароля по ссылке из письма: вызывает `App\Actions\Identity\ResetPassword`,
которая меняет пароль и отзывает все ранее выданные токены API, затем возвращает на страницу входа
с сообщением. Закрывает FR-UI-107 и контракт `auth.reset-password-form` (ЧТЗ 8.2).

## Расположение и вызов
- Код: `app/Livewire/Auth/ResetPasswordForm.php`, шаблон `resources/views/livewire/auth/reset-password-form.blade.php`
- Вызов: `<livewire:auth.reset-password-form :token="$token" :email="$email" />` со страницы
  `resources/views/auth/reset-password.blade.php` (маршрут `password.reset`)

## Входные параметры и слоты
- `mount(string $token, string $email)`: значения из подписанной ссылки письма.
- Состояние — свойства `email`, `token`, `password`, `password_confirmation`.
- Адрес показан полем только для чтения: он уже подтверждён подписью ссылки.

## Поведение и состояния
- `reset()`: валидация набором `App\Http\Requests\Auth\AuthRules::passwordReset()` (общий
  с API-запросом `ResetPasswordRequest`) → `ResetPassword` → `session()->flash('status', 'auth.password_reset')`
  → редирект на `login`.
- Использованная или подделанная ссылка — ошибка у поля `email` текстом
  `errors.codes.INVALID_SIGNATURE.detail`; пароль не меняется.
- Имя действия `reset` задано контрактом ЧТЗ 8.2. Livewire объявляет собственный `reset(...$properties)`
  для сброса свойств, поэтому вызов без аргументов устанавливает пароль, а вызов с именами свойств
  уходит в базовый метод.
- Состояния: форма, отправка (`wire:loading`), ошибка у поля.
- Клавиатура и фокус: нативные `label`/`input`; адрес доступен для чтения, пароли — с `autocomplete="new-password"`.

## Ограничения
- Не проверяет подпись ссылки: это делает middleware `signed` на маршруте.
- Не отзывает токены по месту: это делает операция `ResetPassword` (ЧТЗ 5.2, пункт 12).
- Не раскрывает причину отказа: текст одинаков для просроченной, использованной и подделанной ссылки.

## Где используется
- `resources/views/auth/reset-password.blade.php` — страница маршрута `password.reset`
  (GET `/reset-password/{token}`).
- Та же операция у POST-маршрута `password.reset`
  (`App\Http\Controllers\Web\Auth\ResetPasswordController`).

## Какие тесты покрывают
- `tests/Feature/Livewire/Auth/PasswordRecoveryTest.php` — смена пароля, отзыв токенов, редирект на `login`
  с сообщением, ошибка при использованной ссылке, подтверждение пароля.
- `tests/Feature/Web/PasswordResetWebTest.php` — HTML-форма по ссылке, POST без Livewire, 403 на испорченной ссылке.
