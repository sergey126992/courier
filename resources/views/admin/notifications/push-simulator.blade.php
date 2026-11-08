<x-layouts.app :title="__('notifications.admin.simulator_title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('notifications.admin.simulator_title') }}</h1>

            <a class="btn btn-secondary btn-sm" href="{{ route('notifications.failed') }}">
                {{ __('notifications.admin.back_to_journal') }}
            </a>
        </div>

        <p class="notification-source" role="status">{{ __('notifications.admin.source') }}</p>

        @if ($unavailable)
            <x-app.alert variant="error" :title="__('notifications.admin.service_unavailable')">
                {{ __('notifications.admin.service_unavailable_description') }}
            </x-app.alert>
        @elseif ($rows === [])
            <x-app.empty-state
                :title="__('notifications.admin.push_empty_title')"
                :description="__('notifications.admin.push_empty_description')"
            />
        @else
            <x-app.scroll-area>
                <ul class="journal-list">
                    @foreach ($rows as $row)
                        <li>
                            <x-app.push-message :message="$row" />
                        </li>
                    @endforeach
                </ul>
            </x-app.scroll-area>
        @endif
    </div>
</x-layouts.app>
