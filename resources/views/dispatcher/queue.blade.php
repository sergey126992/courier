<x-layouts.app :title="__('dispatch.labels.queue_title')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ __('dispatch.labels.queue_title') }}</h1>
        </div>

        {{-- Очередь читает только DispatcherQueueQuery и обновляется по конфигурации. --}}
        <livewire:dispatcher.order-queue />
    </div>
</x-layouts.app>
