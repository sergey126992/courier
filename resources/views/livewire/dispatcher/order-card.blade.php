<div
    class="flex flex-col gap-4"
    wire:loading.attr="aria-busy"
    wire:target="loadCandidates,assign,reassign"
>
    {{-- Место под сообщение зарезервировано: появление не сдвигает карточку (FR-UI-185). --}}
    <div class="min-h-12">
        @if (filled($error))
            <x-app.alert variant="error">{{ $error }}</x-app.alert>
        @elseif (filled($status))
            <x-app.alert variant="success">{{ $status }}</x-app.alert>
        @endif
    </div>

    <x-app.card :title="$details['number']">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-app.status-badge :status="$details['status']" />

                <x-app.link :href="route('dispatcher.queue')">{{ __('dispatch.labels.back_to_queue') }}</x-app.link>
            </div>
        </x-slot:actions>

        <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.pickup') }}</dt>
                <dd class="list-value text-sm" title="{{ $details['pickup'] }}">{{ $details['pickup'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.dropoff') }}</dt>
                <dd class="list-value text-sm" title="{{ $details['dropoff'] }}">{{ $details['dropoff'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.transport') }}</dt>
                <dd class="text-sm">{{ $details['transport'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.create_order.weight_grams') }}</dt>
                <dd class="text-sm">{{ $details['weight'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.eta') }}</dt>
                <dd class="text-sm">{{ $details['eta'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.price') }}</dt>
                <dd class="text-sm font-semibold">{{ $details['price'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.created_at') }}</dt>
                <dd class="text-sm">{{ $details['created_at'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.requested_at') }}</dt>
                <dd class="text-sm">{{ $details['requested_at'] }}</dd>
            </div>
        </dl>
    </x-app.card>

    {{-- Статус доставки уведомлений по каналам (FR-NTF-284, ЧТЗ 9.6 п. 3). --}}
    <x-app.card :title="__('orders.labels.notifications')">
        <x-app.notification-status :items="$notifications" />
    </x-app.card>

    <x-app.card :title="__('dispatch.labels.assignment')">
        <form wire:submit="assign" class="flex flex-col gap-4" novalidate>
            <x-app.field name="strategy" :label="__('dispatch.labels.strategy')" :hint="__('dispatch.labels.strategy_hint')">
                <x-app.select
                    name="strategy"
                    wire:model="strategy"
                    :options="$strategyOptions"
                    :placeholder="$defaultStrategyLabel"
                />
            </x-app.field>

            <div class="flex flex-wrap gap-2">
                {{-- Действия определяются доменным переходом, недоступное отключено с пояснением (FR-UI-187). --}}
                <x-app.button
                    variant="secondary"
                    type="button"
                    wire:click="loadCandidates"
                    wire:target="loadCandidates"
                    :disabled="! $canShowCandidates"
                >
                    {{ __('ui.actions.show_candidates') }}
                </x-app.button>

                <x-app.button
                    type="submit"
                    wire:target="assign"
                    :disabled="! $canAssign"
                    wire:loading.attr="disabled"
                >
                    {{ __('ui.actions.assign') }}
                </x-app.button>

                <x-app.button
                    variant="secondary"
                    type="button"
                    wire:click="reassign"
                    wire:target="reassign"
                    :disabled="! $canReassign || $assigning"
                    wire:loading.attr="disabled"
                >
                    {{ __('ui.actions.reassign') }}
                </x-app.button>
            </div>

            @if (! $canAssign && ! $canReassign)
                <p class="text-sm text-muted">{{ __('dispatch.labels.actions_unavailable') }}</p>
            @endif
        </form>
    </x-app.card>

    <x-app.card :title="__('dispatch.labels.candidates')" :subtitle="$appliedStrategyLabel">
        @if (! $candidatesLoaded)
            <p class="text-sm text-muted">{{ __('dispatch.labels.candidates_idle') }}</p>
        @elseif ($candidates === [])
            {{-- Пустой список — штатное состояние, а не ошибка страницы (FR-UI-136, 188). --}}
            <x-app.empty-state
                :title="__('ui.states.empty_candidates_title')"
                :description="__('ui.states.empty_candidates_description')"
            >
                <x-slot:action>
                    <x-app.button
                        variant="secondary"
                        type="button"
                        wire:click="loadCandidates"
                        wire:target="loadCandidates"
                    >
                        {{ __('ui.actions.refresh') }}
                    </x-app.button>
                </x-slot:action>
            </x-app.empty-state>
        @else
            <fieldset class="flex flex-col">
                <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                    {{ __('dispatch.labels.select_candidate') }}
                </legend>

                @foreach ($candidates as $candidate)
                    {{-- Строка-переключатель: нативный radio, выбор с клавиатуры, зона не меньше 44 px. --}}
                    <label class="flex min-h-11 cursor-pointer flex-wrap items-center gap-x-4 gap-y-1 border-t border-subtle py-2 first:border-t-0">
                        <input
                            type="radio"
                            name="courier"
                            value="{{ $candidate['id'] }}"
                            wire:model="selectedCourierId"
                            class="h-6 w-6"
                        />

                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="text-sm font-medium">{{ $candidate['name'] }}</span>
                            <span class="text-xs text-muted">{{ $candidate['transport'] }}</span>
                        </span>

                        <span class="text-xs text-muted">
                            {{ __('dispatch.labels.distance_to_pickup') }}:
                            {{ $candidate['distance'] ?? __('dispatch.labels.no_position') }}
                        </span>

                        <span class="text-xs text-muted">
                            {{ __('dispatch.labels.assignments_per_shift') }}: {{ $candidate['assignments'] }}
                        </span>

                        <span class="text-xs text-muted">
                            {{ __('dispatch.labels.approach_time') }}:
                            {{ $candidate['approach'] ?? __('dispatch.labels.no_position') }}
                        </span>
                    </label>
                @endforeach
            </fieldset>
        @endif
    </x-app.card>

    <x-app.card :title="__('orders.labels.history')">
        @if ($details['history'] === [])
            <p class="text-sm text-muted">{{ __('orders.labels.no_history') }}</p>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($details['history'] as $record)
                    <li class="flex flex-col gap-1 border-t border-subtle pt-3 first:border-t-0 first:pt-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-medium">{{ $record['status'] }}</span>
                            <span class="text-xs text-muted">{{ $record['at'] }}</span>
                        </div>

                        <p class="list-value text-sm">
                            <span class="text-muted">{{ __('orders.labels.changed_by') }}:</span>
                            {{ $record['author'] ?? __('orders.labels.system') }}
                        </p>

                        @if (filled($record['reason']))
                            <p class="list-value text-sm">
                                <span class="text-muted">{{ __('orders.labels.reason') }}:</span>
                                {{ $record['reason'] }}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-app.card>
</div>
