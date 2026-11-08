<div
    class="flex flex-col gap-4"
    x-data
    x-on:dialog-confirmed.window="if ($event.detail.name === 'cancel-order') $wire.cancel()"
    wire:loading.attr="aria-busy"
    wire:target="cancel"
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

                {{-- Трекинг появится отдельным маршрутом: ссылка только если он объявлен. --}}
                @if (\Illuminate\Support\Facades\Route::has('cabinet.orders.tracking'))
                    <x-app.link :href="route('cabinet.orders.tracking', ['order' => $orderId])">
                        {{ __('orders.labels.tracking') }}
                    </x-app.link>
                @endif
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
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.tracking_code') }}</dt>
                <dd class="list-value text-sm" title="{{ $details['tracking_code'] }}">{{ $details['tracking_code'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.eta') }}</dt>
                <dd class="text-sm">{{ $details['eta'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.create_order.distance') }}</dt>
                <dd class="text-sm">{{ $details['distance'] }}</dd>
            </div>
        </dl>

        <div class="mt-6">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.parcel') }}</h3>

            <dl class="mt-2 grid gap-x-6 gap-y-3 sm:grid-cols-4">
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('ui.create_order.weight_grams') }}</dt>
                    <dd class="text-sm">{{ $details['parcel']['weight'] }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('ui.create_order.length') }}</dt>
                    <dd class="text-sm">{{ $details['parcel']['length'] }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('ui.create_order.width') }}</dt>
                    <dd class="text-sm">{{ $details['parcel']['width'] }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">{{ __('ui.create_order.height') }}</dt>
                    <dd class="text-sm">{{ $details['parcel']['height'] }}</dd>
                </div>

                @if ($details['parcel']['declared'] !== null)
                    <div class="flex flex-col gap-1 sm:col-span-2">
                        <dt class="text-xs text-muted">{{ __('orders.labels.declared_value') }}</dt>
                        <dd class="text-sm">{{ $details['parcel']['declared'] }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="mt-6">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.price') }}</h3>
            <p class="mt-1 text-lg font-semibold">{{ $details['price'] }}</p>

            @if ($details['breakdown'] !== [])
                <ul class="mt-3 flex flex-col gap-2">
                    @foreach ($details['breakdown'] as $line)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-t border-subtle pt-2">
                            <span class="min-w-0">{{ $line['label'] }}</span>
                            <span class="font-medium">{{ $line['amount'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-3 sm:grid-cols-3">
            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.created_at') }}</dt>
                <dd class="text-sm">{{ $details['created_at'] }}</dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.requested_at') }}</dt>
                <dd class="text-sm">{{ $details['requested_at'] }}</dd>
            </div>

            @if ($details['cancelled_at'] !== null)
                <div class="flex flex-col gap-1">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.cancelled_at') }}</dt>
                    <dd class="text-sm">{{ $details['cancelled_at'] }}</dd>
                </div>
            @endif
        </dl>
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

    @if ($canCancel)
        <div class="flex flex-wrap gap-2">
            <x-app.button variant="danger" type="button" x-on:click="$dispatch('open-modal', 'cancel-order')">
                {{ __('ui.actions.cancel_order') }}
            </x-app.button>
        </div>

        {{-- Отмена подтверждается существующим диалогом; причина необязательна (FR-UI-127). --}}
        <x-app.confirm-dialog
            name="cancel-order"
            :title="__('ui.confirm.cancel_order')"
            :confirm-label="__('ui.actions.cancel_order')"
        >
            <p class="text-sm">{{ __('ui.confirm.cancel_order_description') }}</p>

            <x-app.field name="cancelReason" :label="__('orders.labels.cancel_reason')">
                <x-app.textarea name="cancelReason" wire:model="cancelReason" :maxlength="255" />
            </x-app.field>
        </x-app.confirm-dialog>
    @endif
</div>
