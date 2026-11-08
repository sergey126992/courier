<x-layouts.app :title="__('ui.order_details.title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            {{-- Номер может быть длинным: обрезается с полным значением в title (FR-UI-183). --}}
            <h1 class="list-value text-xl font-semibold" title="{{ $order->number }}">
                {{ __('ui.order_details.title') }} {{ $order->number }}
            </h1>

            <x-app.link :href="route('dispatcher.queue')">{{ __('dispatch.labels.back_to_queue') }}</x-app.link>
        </div>

        {{-- Карточка назначает курьера через Actions, без запросов к базе из Blade. --}}
        <livewire:dispatcher.order-card :order-id="$order->id" />
    </div>
</x-layouts.app>
