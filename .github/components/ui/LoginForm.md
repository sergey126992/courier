# LoginForm

## Назначение
Форма входа по email и паролю для браузера: проверяет учётные данные общей операцией
`App\Actions\Identity\AuthenticateUser`, открывает сессию guard `web` и отправляет на запомненный
адрес или домашнюю страницу роли. Закрывает FR-UI-101…104, FR-UI-109 и контракт `auth.login-form`
(ЧТЗ 8.2).

## Расположение и вызов
- Код: `app/Livewire/Auth/LoginForm.php`, шаблон `resources/views/livewire/auth/login-form.blade.php`
- Вызов: `<livewire:auth.login-form />` со страницы `resources/views/auth/login.blade.php` (маршрут `login`)

## Входные параметры и слоты
Livewire-компонент без параметров; состояние — свойства:
- `email` (`string`): адрес; нормализуется в `AuthenticateUser` (нижний регистр, trim).
- `password` (`string`): пароль; в разметке только `type="password"`.
- `remember` (`bool`): «Запомнить меня», по умолчанию выключено (FR-UI-109).

## Поведение и состояния
- `login()`: валидация (`required`, `string`, `email`, `max:255`) → `AuthenticateUser` → `Auth::login()` →
  `session()->regenerate()` → `redirectIntended(домашняя страница роли)`.
- Неверные данные — ошибка у поля `email` текстом `auth.failed`; для несуществующего адреса текст тот же.
- Превышение лимита — сообщение `auth.throttle` со временем повтора у поля `email`, сессия не открывается.
- Токен Sanctum не создаётся: веб-вход не меняет выданные ранее токены API (FR-UI-103).
- Состояния: обычное, отправка (`wire:loading` блокирует кнопку), ошибка поля.
- Клавиатура и фокус: нативные `label`/`input` через `<x-app.field>` и `<x-app.input>`, фокус видимый.

## Ограничения
- Не сравнивает пароль и не считает попытки — это живёт только в `AuthenticateUser` (FR-UI-102).
- Не решает, куда вести пользователя: адрес даёт `App\Actions\Identity\RoleHomeUrl` по `config('ui.roles.<role>.home')`.
- Не создаёт и не отзывает токены API, не проверяет подтверждение почты и права — это другие пункты плана.
- Обращений к базе и бизнес-логики в компоненте нет: только операции и сессия.

## Где используется
- `resources/views/auth/login.blade.php` — страница маршрута `login` (GET `/login`).
- Та же цепочка операций у POST-маршрута `login.store` (`App\Http\Controllers\Web\Auth\LoginController`).

## Какие тесты покрывают
- `tests/Feature/Livewire/Auth/LoginFormTest.php` — ошибка у поля при неверном пароле и тот же текст для
  несуществующего адреса, редирект по роли, лимит без аутентификации, обязательные поля.
- `tests/Feature/Web/LoginRedirectTest.php` — 302 гостя на `/login`, возврат на запомненный адрес,
  отсутствие записи в `personal_access_tokens`, выход и повторный POST без сессии.
