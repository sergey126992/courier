{{-- Блок отслеживания: анимаций нет, поэтому reduced-motion соблюдается (FR-UI-177). --}}
<div class="flex flex-col gap-4" wire:poll.{{ $refreshSeconds }}s="refresh">
    <x-app.card :title="__('orders.labels.tracking')">
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-muted">
                    {{ __('tracking.labels.status') }}
                </span>
                <x-app.status-badge :status="$tracking['status']" />
            </div>

            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.last_position') }}</dt>
                    <dd class="list-value text-sm">
                        {{ $tracking['last_position'] ?? __('tracking.labels.no_position') }}
                    </dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.eta') }}</dt>
                    <dd class="text-sm">{{ $tracking['eta'] }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.remaining_distance') }}</dt>
                    <dd class="text-sm">{{ $tracking['remaining'] }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.source') }}</dt>
                    <dd class="text-sm">{{ $tracking['source'] }}</dd>
                </div>
            </dl>
        </div>
    </x-app.card>

    <x-app.card :title="__('tracking.labels.events')">
        @if ($tracking['events'] === [])
            <x-app.empty-state
                :title="__('ui.states.empty_tracking_title')"
                :description="__('ui.states.empty_tracking_description')"
            />
        @else
            <x-app.scroll-area max-height="16rem" :label="__('tracking.labels.events')">
                <ul class="flex flex-col gap-2">
                    @foreach ($tracking['events'] as $event)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-t border-subtle pt-2 first:border-t-0 first:pt-0">
                            <span class="list-value text-sm">{{ $event['position'] }}</span>
                            <span class="text-xs text-muted">{{ $event['at'] }}</span>
                            <span class="text-xs text-muted">{{ $event['source'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-app.scroll-area>
        @endif
    </x-app.card>
</div>
