@php
    $channelOptions = $this->channelOptions;
@endphp

<div class="flex flex-col gap-4">
    {{-- Активный отправитель недоступен: сообщение страницы, а не падение (FR-NTF-294). --}}
    @if ($failed)
        <x-app.alert variant="error" :title="__('notifications.admin.service_unavailable')">
            {{ __('notifications.admin.service_unavailable_description') }}
        </x-app.alert>
    @endif

    @if ($message !== null)
        <x-app.alert :variant="$messageVariant">{{ $message }}</x-app.alert>
    @endif

    <x-app.card :title="__('notifications.admin.filters_title')">
        <form wire:submit="applyFilters" class="flex flex-col gap-4" novalidate>
            <div class="flex flex-wrap gap-4">
                <x-app.field name="channel" :label="__('notifications.admin.channel')">
                    <x-app.select
                        name="channel"
                        wire:model="channel"
                        :options="$channelOptions"
                        :placeholder="__('notifications.admin.all_channels')"
                    />
                </x-app.field>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="applyFilters">
                    {{ __('ui.actions.apply_filters') }}
                </x-app.button>
                <x-app.button variant="secondary" type="button" wire:click="applyFilters" wire:loading.attr="disabled" wire:target="applyFilters">
                    {{ __('ui.actions.refresh') }}
                </x-app.button>
            </div>
        </form>
    </x-app.card>

    <p class="text-sm text-muted" role="status" aria-live="polite">{{ $this->countLabel }}</p>

    @if ($rows === [])
        <x-app.empty-state
            :title="__('notifications.admin.empty_title')"
            :description="__('notifications.admin.empty_description')"
        />
    @else
        <ul class="journal-list">
            @foreach ($rows as $row)
                <li wire:key="notification-row-{{ $row['id'] }}">
                    <x-app.notification-row :row="$row">
                        <x-slot:action>
                            <x-app.button
                                variant="secondary"
                                size="sm"
                                type="button"
                                wire:click="retry({{ $row['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="retry({{ $row['id'] }})"
                            >
                                {{ __('ui.actions.retry') }}
                            </x-app.button>
                        </x-slot:action>
                    </x-app.notification-row>
                </li>
            @endforeach
        </ul>

        @if ($hasMore)
            <div class="flex flex-wrap gap-2">
                <x-app.button variant="secondary" type="button" wire:click="loadMore" wire:loading.attr="aria-busy" wire:target="loadMore">
                    {{ __('ui.actions.show_more') }}
                </x-app.button>
            </div>
        @endif
    @endif
</div>
