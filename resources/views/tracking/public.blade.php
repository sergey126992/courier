<x-layouts.app :title="__('tracking.labels.public_title')">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">{{ __('tracking.labels.public_title') }}</h1>
            <p class="text-sm text-muted">{{ __('tracking.messages.public_notice') }}</p>
        </div>

        <x-app.card :title="__('tracking.labels.status')">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-app.status-badge :status="$tracking->status" :label="$tracking->statusLabel" />

                <span class="text-xs text-muted">
                    {{ __('tracking.labels.updated_at') }}: {{ $updatedAt ?? __('tracking.labels.no_data') }}
                </span>
            </div>

            <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.district') }}</dt>
                    <dd class="list-value text-sm">{{ $tracking->district ?? __('tracking.labels.no_data') }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.eta') }}</dt>
                    <dd class="text-sm">
                        {{ $etaAt ?? __('tracking.labels.no_data') }}
                        @if ($tracking->etaMinutes > 0)
                            <span class="text-muted">· {{ __('ui.formats.minutes', ['minutes' => $tracking->etaMinutes]) }}</span>
                        @endif
                    </dd>
                </div>

                {{-- Публичный снимок содержит только зафиксированный прогноз,
                     поэтому источник данных всегда расчётный (FR-TRK-125, FR-TRK-134). --}}
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('tracking.labels.source') }}</dt>
                    <dd class="text-sm">{{ __('tracking.labels.source_planned') }}</dd>
                </div>
            </dl>
        </x-app.card>
    </div>
</x-layouts.app>
