<x-layouts.app :title="__('ui.nav.orders')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('ui.nav.orders') }}</h1>

            {{-- Создание заказа появится отдельным экраном: ссылка только если маршрут объявлен. --}}
            @if (\Illuminate\Support\Facades\Route::has('cabinet.orders.create'))
                <a class="btn btn-primary" href="{{ route('cabinet.orders.create') }}">
                    {{ __('ui.actions.create_order') }}
                </a>
            @endif
        </div>

        <livewire:cabinet.order-list />
    </div>
</x-layouts.app>
