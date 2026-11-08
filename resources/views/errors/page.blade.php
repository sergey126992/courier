@php
    $status = (string) ($code ?? 500);
    $user = auth()->user();
    $actionRoute = 'home';
    $actionLabel = __('ui.actions.back_home');

    if ($status === '419') {
        $actionRoute = 'login';
        $actionLabel = __('ui.actions.back_to_login');
    } elseif ($user !== null) {
        // Домашний адрес берётся из конфигурации роли; если раздел ещё не
        // появился, действие ведёт в профиль, доступный любой роли (FR-UI-116).
        $roleHome = (string) config('ui.roles.'.$user->role->value.'.home');
        $actionRoute = \Illuminate\Support\Facades\Route::has($roleHome) ? $roleHome : 'account';
        $actionLabel = __('ui.actions.back_to_cabinet');
    }
@endphp

<x-layouts.app :title="__('ui.errors.'.$status.'.title')">
    <div class="mx-auto w-full max-w-md">
        <x-app.card :title="__('ui.errors.'.$status.'.title')">
            <p class="text-sm text-muted">{{ __('ui.errors.'.$status.'.description') }}</p>

            <div class="mt-4">
                <a class="btn btn-primary" href="{{ route($actionRoute) }}">{{ $actionLabel }}</a>
            </div>
        </x-app.card>
    </div>
</x-layouts.app>
