<x-layouts.app :title="__('ui.order_details.title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            {{-- Номер может быть длинным: обрезается с полным значением в title (FR-UI-183). --}}
            <h1 class="list-value text-xl font-semibold" title="{{ $order->number }}">
                {{ __('ui.order_details.title') }} {{ $order->number }}
            </h1>

            <x-app.link :href="route('cabinet.orders')">{{ __('ui.actions.back_to_orders') }}</x-app.link>
        </div>

        <livewire:cabinet.order-details :order-id="$order->id" />
    </div>
</x-layouts.app>
