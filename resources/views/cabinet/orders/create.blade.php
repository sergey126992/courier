<x-layouts.app :title="__('ui.create_order.title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('ui.create_order.title') }}</h1>

            <a class="btn btn-quiet" href="{{ route('cabinet.orders') }}">{{ __('ui.actions.back_to_cabinet') }}</a>
        </div>

        <livewire:cabinet.create-order />
    </div>
</x-layouts.app>
