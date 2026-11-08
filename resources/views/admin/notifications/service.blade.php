<x-layouts.app :title="__('notifications.admin.service_title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('notifications.admin.service_title') }}</h1>

            <a class="btn btn-secondary btn-sm" href="{{ route('notifications.failed') }}">
                {{ __('notifications.admin.back_to_journal') }}
            </a>
        </div>

        {{-- Источник данных один — сервис оповещений (FR-NTF-405, FR-NTF-511). --}}
        <p class="notification-source" role="status">{{ __('notifications.admin.source') }}</p>

        <livewire:admin.notification-service-page />
    </div>
</x-layouts.app>
