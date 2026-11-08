<x-layouts.app :title="__('notifications.admin.journal_title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('notifications.admin.journal_title') }}</h1>

            <a class="btn btn-secondary btn-sm" href="{{ route('notifications.push-simulator') }}">
                {{ __('notifications.admin.open_simulator') }}
            </a>
        </div>

        {{-- Источник данных один — сервис оповещений (FR-NTF-405). --}}
        <p class="notification-source" role="status">{{ __('notifications.admin.source') }}</p>

        <livewire:admin.notification-journal />
    </div>
</x-layouts.app>
