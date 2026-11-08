@php
    $channelOptions = $this->channelOptions;
    $stateOptions = $this->stateOptions;
@endphp

<x-app.tabs name="notification-service" :tabs="[
    'dlq' => __('notifications.admin.dlq.tab'),
    'metrics' => __('notifications.admin.metrics.tab'),
]">
    <div
        role="tabpanel"
        id="notification-service-panel-dlq"
        aria-labelledby="notification-service-tab-dlq"
        class="flex flex-col gap-4"
    >
        @if ($failed)
            <x-app.alert variant="error" :title="__('notifications.admin.service_unavailable')">
                {{ __('notifications.admin.service_unavailable_description') }}
            </x-app.alert>
        @endif

        @if ($message !== null)
            <x-app.alert :variant="$messageVariant">{{ $message }}</x-app.alert>
        @endif

        <x-app.card :title="__('notifications.admin.dlq.filters_title')">
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

                    <x-app.field name="state" :label="__('notifications.admin.dlq.state')">
                        <x-app.select
                            name="state"
                            wire:model="state"
                            :options="$stateOptions"
                            :placeholder="__('notifications.admin.dlq.all_states')"
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
                :title="__('notifications.admin.dlq.empty_title')"
                :description="__('notifications.admin.dlq.empty_description')"
            />
        @else
            <x-app.scroll-area :label="__('notifications.admin.dlq.list_label')">
                <ul class="journal-list">
                    @foreach ($rows as $row)
                        <li wire:key="dlq-row-{{ $row['id'] }}">
                            <x-app.notification-row :row="$row">
                                @if ($row['replayable'])
                                    <x-slot:action>
                                        <x-app.button
                                            variant="secondary"
                                            size="sm"
                                            type="button"
                                            wire:click="replay({{ $row['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="replay({{ $row['id'] }})"
                                        >
                                            {{ __('notifications.admin.dlq.replay') }}
                                        </x-app.button>
                                    </x-slot:action>
                                @endif
                            </x-app.notification-row>
                        </li>
                    @endforeach
                </ul>
            </x-app.scroll-area>

            @if ($hasMore)
                <div class="flex flex-wrap gap-2">
                    <x-app.button variant="secondary" type="button" wire:click="loadMore" wire:loading.attr="aria-busy" wire:target="loadMore">
                        {{ __('ui.actions.show_more') }}
                    </x-app.button>
                </div>
            @endif
        @endif
    </div>

    {{-- Вкладка метрик: снимок значений сервиса (FR-OPS-511). --}}
    <div
        role="tabpanel"
        id="notification-service-panel-metrics"
        aria-labelledby="notification-service-tab-metrics"
        class="flex flex-col gap-4"
        hidden
    >
        @if ($metricsFailed)
            <x-app.alert variant="error" :title="__('notifications.admin.service_unavailable')">
                {{ __('notifications.admin.service_unavailable_description') }}
            </x-app.alert>
        @elseif (! $this->hasMetrics)
            <x-app.empty-state
                :title="__('notifications.admin.metrics.empty_title')"
                :description="__('notifications.admin.metrics.empty_description')"
            />
        @else
            <x-app.card
                :title="__('notifications.admin.metrics.counters_title')"
                :subtitle="__('notifications.admin.metrics.subtitle')"
            >
                @if ($this->counterRows === [])
                    <p class="text-sm text-muted">{{ __('notifications.admin.metrics.empty_description') }}</p>
                @else
                    <x-app.data-list
                        :columns="[
                            'channel' => __('notifications.admin.metrics.channel'),
                            'metric' => __('notifications.admin.metrics.metric'),
                            'value' => __('notifications.admin.metrics.value'),
                        ]"
                        :rows="$this->counterRows"
                    />
                @endif
            </x-app.card>

            <x-app.card :title="__('notifications.admin.metrics.dlq_title')">
                @if ($this->dlqDepthRows === [])
                    <p class="text-sm text-muted">{{ __('notifications.admin.metrics.empty_description') }}</p>
                @else
                    <x-app.data-list
                        :columns="[
                            'channel' => __('notifications.admin.metrics.channel'),
                            'state' => __('notifications.admin.metrics.state'),
                            'value' => __('notifications.admin.metrics.value'),
                        ]"
                        :rows="$this->dlqDepthRows"
                    />
                @endif
            </x-app.card>

            <x-app.card :title="__('notifications.admin.metrics.consumer_title')">
                @if ($this->consumerRows === [])
                    <p class="text-sm text-muted">{{ __('notifications.admin.metrics.empty_description') }}</p>
                @else
                    <x-app.data-list
                        :columns="[
                            'stream' => __('notifications.admin.metrics.stream'),
                            'lag_seconds' => __('notifications.admin.metrics.lag_seconds'),
                            'pending' => __('notifications.admin.metrics.pending'),
                        ]"
                        :rows="$this->consumerRows"
                    />
                @endif
            </x-app.card>

            <x-app.card :title="__('notifications.admin.metrics.outbox_title')">
                <x-app.data-list
                    :columns="[
                        'source' => __('notifications.admin.metrics.outbox_source'),
                        'age_seconds' => __('notifications.admin.metrics.value'),
                    ]"
                    :rows="$this->outboxRows"
                />
            </x-app.card>

            <div class="flex flex-wrap gap-2">
                <x-app.button
                    variant="secondary"
                    type="button"
                    wire:click="refreshMetrics"
                    wire:loading.attr="aria-busy"
                    wire:target="refreshMetrics"
                >
                    {{ __('ui.actions.refresh') }}
                </x-app.button>
            </div>

            <p class="text-sm text-muted" role="status" aria-live="polite">{{ __('notifications.admin.source') }}</p>
        @endif
    </div>
</x-app.tabs>
