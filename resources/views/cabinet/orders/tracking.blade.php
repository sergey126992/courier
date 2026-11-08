<x-layouts.app :title="__('tracking.labels.public_title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            {{-- Номер может быть длинным: обрезается с полным значением в title (FR-UI-183). --}}
            <h1 class="list-value text-xl font-semibold" title="{{ $order->number }}">
                {{ __('tracking.labels.public_title') }} {{ $order->number }}
            </h1>

            <x-app.link :href="route('cabinet.orders.show', ['order' => $order->id])">
                {{ __('ui.actions.back_to_orders') }}
            </x-app.link>
        </div>

        <livewire:cabinet.order-tracking :order-id="$order->id" />

        <x-app.card :title="__('tracking.labels.copy_link')">
            <livewire:cabinet.tracking-link-button :order-id="$order->id" />
        </x-app.card>
    </div>
</x-layouts.app>
