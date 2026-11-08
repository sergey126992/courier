@php
    $zoneOptions = $this->zoneOptions;
    $transportOptions = $this->transportOptions;
    $rows = $this->rows;
@endphp

{{-- Автообновление охватывает только очередь: открытое модальное окно страницы
     не перерисовывается. При паузе атрибут не выводится — запросов нет (FR-UI-133). --}}
<div class="flex flex-col gap-4" @if (! $paused) wire:poll.{{ $refreshSeconds }}s="refresh" @endif>
    {{-- Ошибка загрузки: сообщение страницы, а не пустой экран (FR-UI-184). --}}
    @if ($failed)
        <x-app.alert variant="error" :title="__('ui.states.load_error')" />
    @endif

    <x-app.card :title="__('ui.filters.title')">
        <form wire:submit="applyFilters" class="flex flex-col gap-4" novalidate>
            <div class="flex flex-wrap gap-4">
                <x-app.field name="zone_id" :label="__('ui.filters.zone')">
                    <x-app.select
                        name="zone_id"
                        wire:model="zoneId"
                        :options="$zoneOptions"
                        :placeholder="__('ui.create_order.select')"
                    />
                </x-app.field>

                <x-app.field name="from" :label="__('ui.filters.period_from')">
                    <x-app.input name="from" type="date" wire:model="from" />
                </x-app.field>

                <x-app.field name="to" :label="__('ui.filters.period_to')">
                    <x-app.input name="to" type="date" wire:model="to" />
                </x-app.field>

                <x-app.field name="transport" :label="__('ui.filters.transport')">
                    <x-app.select
                        name="transport"
                        wire:model="transport"
                        :options="$transportOptions"
                        :placeholder="__('ui.create_order.select')"
                    />
                </x-app.field>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="applyFilters">
                    {{ __('ui.actions.apply_filters') }}
                </x-app.button>

                {{-- Состояние паузы видно текстом в строке количества ниже. --}}
                <x-app.button variant="secondary" type="button" wire:click="togglePause" wire:target="togglePause">
                    {{ $paused ? __('ui.actions.resume_refresh') : __('ui.actions.pause_refresh') }}
                </x-app.button>
            </div>
        </form>
    </x-app.card>

    <div class="flex flex-wrap items-center justify-between gap-2">
        {{-- Количество заказов объявляется при обновлении (FR-UI-133, 177). --}}
        <p class="text-sm text-muted" role="status" aria-live="polite">{{ $this->orderCountLabel }}</p>

        <p class="text-sm {{ $paused ? 'text-warning' : 'text-muted' }}">
            {{ $paused ? __('dispatch.labels.paused') : __('dispatch.labels.active') }}
        </p>
    </div>

    @if ($rows->isEmpty())
        {{-- Пустая очередь объясняет причину и предлагает основное действие (FR-UI-182). --}}
        <x-app.empty-state
            :title="__('ui.states.empty_queue_title')"
            :description="__('ui.states.empty_queue_description')"
        >
            <x-slot:action>
                <x-app.button variant="secondary" type="button" wire:click="applyFilters" wire:target="applyFilters">
                    {{ __('ui.actions.refresh') }}
                </x-app.button>
            </x-slot:action>
        </x-app.empty-state>
    @else
        <x-app.data-list :columns="$columns" :rows="$rows->all()" />

        @if ($hasMore)
            {{-- Курсорная выдача: следующая порция добавляется без перезагрузки. --}}
            <div class="flex flex-wrap gap-2">
                <x-app.button
                    variant="secondary"
                    type="button"
                    wire:click="loadMore"
                    wire:loading.attr="aria-busy"
                    wire:target="loadMore"
                >
                    {{ __('ui.actions.show_more') }}
                </x-app.button>
            </div>
        @endif
    @endif
</div>
