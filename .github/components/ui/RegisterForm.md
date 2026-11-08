# RegisterForm

## Назначение
Форма регистрации отправителя в браузере: создаёт аккаунт и профиль одной операцией
`App\Actions\Identity\RegisterSender`, ставит письмо подтверждения в очередь, открывает сессию
guard `web` и отправляет на `verification.notice`. Закрывает FR-UI-105 и контракт
`auth.register-form` (ЧТЗ 8.2).

## Расположение и вызов
- Код: `app/Livewire/Auth/RegisterForm.php`, шаблон `resources/views/livewire/auth/register-form.blade.php`
- Вызов: `<livewire:auth.register-form />` со страницы `resources/views/auth/register.blade.php`
  (маршрут `register`, GET `/register`)

## Входные параметры и слоты
Livewire-компонент без параметров; состояние — свойства:
- `name` (`string`): имя; пробелы по краям обрезаются перед сохранением.
- `email` (`string`): адрес; приводится к нижнему регистру перед проверкой и сохранением.
- `password` (`string`) и `password_confirmation` (`string`): пароль и повтор, минимум 8 символов.

## Поведение и состояния
- `register()`: валидация набором `App\Http\Requests\Auth\AuthRules::registration()` (тот же список,
  что у API-запроса `RegisterRequest`) → `RegisterSender` → `Auth::login()` → `session()->regenerate()`
  → редирект на `verification.notice`.
- Письмо подтверждения уходит в очередь через `SendEmailVerification`; ссылка строится на маршрут
  `config('ui.links.email_verification_route')` — по умолчанию `verification.verify.web`.
- Занятый email (без учёта регистра) — ошибка у поля `email`, второй пользователь не создаётся,
  письмо не ставится в очередь.
- Состояния: обычное, отправка (`wire:loading` блокирует кнопку), ошибки у полей.
- Клавиатура и фокус: нативные `label`/`input` через `<x-app.field>` и `<x-app.input>`, фокус видимый.

## Ограничения
- Не содержит списка правил: он общий с API в `AuthRules`, копий рядом с формой нет.
- Не отправляет письмо и не сравнивает пароли сам — это операции `App\Actions\Identity`.
- Не выдаёт токен Sanctum, не подтверждает почту и не проверяет права роли.
- На странице нет литеральных цветов и своих обёрток документа: только общий layout и `<x-app.*>`.

## Где используется
- `resources/views/auth/register.blade.php` — страница маршрута `register`.
- Та же цепочка операций у POST-маршрута `register.store`
  (`App\Http\Controllers\Web\Auth\RegisterController`).

## Какие тесты покрывают
- `tests/Feature/Livewire/Auth/RegisterFormTest.php` — аккаунт и профиль, письмо в очереди, ссылка
  письма на web-маршрут, занятый email, обязательные поля и подтверждение пароля.
- `tests/Feature/Web/EmailVerificationWebTest.php` — форма регистрации, POST без Livewire, подтверждение почты.
