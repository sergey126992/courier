@php
    $statusOptions = [];

    foreach (\App\Domain\Order\OrderStatus::cases() as $status) {
        $statusOptions[$status->value] = $status->label();
    }

    $hasFilters = $this->hasActiveFilters;
@endphp

<div class="flex flex-col gap-4">
    {{-- Ошибка загрузки: сообщение страницы, а не пустой экран (FR-UI-184). --}}
    @if ($failed)
        <x-app.alert variant="error" :title="__('ui.states.load_error')" />
    @endif

    <x-app.card :title="__('ui.filters.title')">
        <form wire:submit="applyFilters" class="flex flex-col gap-4" novalidate>
            <fieldset class="flex flex-col gap-2">
                <legend class="text-xs font-semibold uppercase tracking-wide text-muted">
                    {{ __('ui.filters.status') }}
                </legend>

                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    @foreach ($statusOptions as $value => $label)
                        <x-app.checkbox
                            name="statuses"
                            :id="'status-'.$value"
                            :value="$value"
                            :label="$label"
                            wire:model="statuses"
                        />
                    @endforeach
                </div>
            </fieldset>

            <div class="flex flex-wrap gap-4">
                <x-app.field name="from" :label="__('ui.filters.period_from')">
                    <x-app.input name="from" type="date" wire:model="from" />
                </x-app.field>

                <x-app.field name="to" :label="__('ui.filters.period_to')">
                    <x-app.input name="to" type="date" wire:model="to" />
                </x-app.field>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="applyFilters">
                    {{ __('ui.actions.apply_filters') }}
                </x-app.button>

                <x-app.button variant="secondary" type="button" wire:click="resetFilters" wire:target="resetFilters">
                    {{ __('ui.actions.reset_filters') }}
                </x-app.button>
            </div>
        </form>
    </x-app.card>

    @if ($this->orders->isEmpty())
        {{-- Пустая выборка объясняет причину и предлагает основное действие (FR-UI-182). --}}
        <x-app.empty-state
            :title="$hasFilters ? __('ui.states.empty_filtered_title') : __('ui.states.empty_orders_title')"
            :description="$hasFilters ? __('ui.states.empty_filtered_description') : __('ui.states.empty_orders_description')"
        >
            @if ($hasFilters)
                <x-slot:action>
                    <x-app.button variant="secondary" type="button" wire:click="resetFilters">
                        {{ __('ui.actions.reset_filters') }}
                    </x-app.button>
                </x-slot:action>
            @endif
        </x-app.empty-state>
    @else
        <x-app.data-list
            :columns="$columns"
            :rows="$this->orders->all()"
            :row="static fn (array $item): mixed => $item['open'] ?? null"
        />

        @if ($hasMore)
            {{-- Курсорная выдача: «Показать ещё» с запасным адресом без JavaScript. --}}
            <x-app.pagination
                :has-more="$hasMore"
                :next-url="$this->nextPageUrl"
                wire:click.prevent="loadMore"
                wire:loading.attr="aria-busy"
                wire:target="loadMore"
            />
        @endif
    @endif
</div>
