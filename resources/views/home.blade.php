<x-layouts.app :title="__('ui.landing.title')">
    <div class="mx-auto w-full max-w-2xl">
        <x-app.card :title="__('ui.landing.title')">
            <p class="text-sm text-muted">{{ __('ui.landing.description') }}</p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.landing.register') }}</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">{{ __('ui.landing.login') }}</a>
            </div>
        </x-app.card>
    </div>
</x-layouts.app>
