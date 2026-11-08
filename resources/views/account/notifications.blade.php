<x-layouts.app :title="__('ui.notifications.title')">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('ui.notifications.title') }}</h1>

            <x-app.link :href="route('account')">{{ __('ui.nav.account') }}</x-app.link>
        </div>

        <livewire:account.notification-preferences />
    </div>
</x-layouts.app>
