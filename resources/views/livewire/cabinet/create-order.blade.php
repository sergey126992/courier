<div class="flex flex-col gap-4">
    @if (filled($error))
        {{-- Общая ошибка операции: алерт формы, данные формы сохраняются (ЧТЗ 8.5). --}}
        <x-app.alert variant="error">{{ $error }}</x-app.alert>
    @endif

    <x-app.card>
        <form wire:submit="estimate" class="flex flex-col gap-6" novalidate>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-app.field name="transport" :label="__('ui.create_order.transport')" required>
                    <x-app.select
                        name="transport"
                        :options="$transportOptions"
                        :placeholder="__('ui.create_order.select')"
                        wire:model="transport"
                    />
                </x-app.field>

                {{-- Имя поля — ключ правила: подпись, id и ошибка связаны одним именем. --}}
                <x-app.field name="light_vehicle_kind" :label="__('ui.create_order.light_vehicle_kind')">
                    <x-app.select
                        name="light_vehicle_kind"
                        :options="$lightVehicleOptions"
                        :placeholder="__('ui.create_order.select')"
                        wire:model="lightVehicleKind"
                    />
                </x-app.field>
            </div>

            {{-- Адреса выбираются из справочника: координаты в форме не вводятся (FR-UI-121).
                 Подпись, подсказка и ошибка поля остаются у x-app.field. --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <x-app.field
                    name="pickup"
                    :label="__('ui.create_order.pickup')"
                    :hint="__('ui.create_order.address_hint')"
                    required
                >
                    <x-app.address-autocomplete
                        name="pickup"
                        :query="$pickupQuery"
                        :suggestions="$pickupSuggestions"
                        wire-model="pickupQuery"
                        select-method="selectPickup"
                        clear-method="clearPickup"
                        :selected="filled($pickupAddressId)"
                    />
                </x-app.field>

                <x-app.field
                    name="dropoff"
                    :label="__('ui.create_order.dropoff')"
                    :hint="__('ui.create_order.address_hint')"
                    required
                >
                    <x-app.address-autocomplete
                        name="dropoff"
                        :query="$dropoffQuery"
                        :suggestions="$dropoffSuggestions"
                        wire-model="dropoffQuery"
                        select-method="selectDropoff"
                        clear-method="clearDropoff"
                        :selected="filled($dropoffAddressId)"
                    />
                </x-app.field>
            </div>

            <fieldset class="field">
                <legend class="field-label">{{ __('ui.create_order.parcel') }}</legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-app.field name="parcel.weight_grams" :label="__('ui.create_order.weight_grams')" required>
                        <x-app.input name="parcel.weight_grams" type="number" min="1" wire:model="parcel.weight_grams" />
                    </x-app.field>

                    <x-app.field name="parcel.declared_value.amount" :label="__('ui.create_order.declared_value')">
                        <x-app.input name="parcel.declared_value.amount" type="number" min="0" wire:model="declaredValue" />
                    </x-app.field>

                    <div class="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                        <x-app.field name="parcel.dimensions.length" :label="__('ui.create_order.length')" required>
                            <x-app.input name="parcel.dimensions.length" type="number" min="1" wire:model="parcel.dimensions.length" />
                        </x-app.field>

                        <x-app.field name="parcel.dimensions.width" :label="__('ui.create_order.width')" required>
                            <x-app.input name="parcel.dimensions.width" type="number" min="1" wire:model="parcel.dimensions.width" />
                        </x-app.field>

                        <x-app.field name="parcel.dimensions.height" :label="__('ui.create_order.height')" required>
                            <x-app.input name="parcel.dimensions.height" type="number" min="1" wire:model="parcel.dimensions.height" />
                        </x-app.field>
                    </div>
                </div>

                @error('parcel')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-app.field name="requested_at" :label="__('ui.create_order.requested_at')">
                    <x-app.input name="requested_at" type="datetime-local" wire:model="requestedAt" />
                </x-app.field>

                <x-app.checkbox name="is_paid_parking" :label="__('ui.create_order.is_paid_parking')" wire:model="isPaidParking" />
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- Второстепенное действие: расчёт ничего не сохраняет (FR-UI-121). --}}
                <x-app.button
                    variant="secondary"
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="estimate"
                >
                    {{ __('ui.actions.calculate') }}
                </x-app.button>

                {{-- Итоговое действие экрана — единственный акцент (FR-UI-202);
                     двойное нажатие не создаёт второй заказ (FR-UI-122). --}}
                <x-app.button
                    type="button"
                    wire:click="create"
                    wire:loading.attr="disabled"
                    wire:loading.attr="aria-busy"
                    wire:target="create"
                >
                    {{ __('ui.actions.create_order') }}
                </x-app.button>
            </div>
        </form>
    </x-app.card>

    @if ($quote !== null)
        {{-- Результат расчёта: цена, прогноз и разбивка, ничего не сохранено (FR-UI-121). --}}
        <x-app.card :title="__('ui.create_order.quote')">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.price') }}</dt>
                    <dd class="text-lg font-semibold">{{ $quote['price'] }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('orders.labels.eta') }}</dt>
                    <dd>{{ __('ui.create_order.minutes', ['minutes' => $quote['eta_minutes']]) }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.create_order.distance') }}</dt>
                    <dd>{{ __('ui.create_order.kilometers', ['distance' => $quote['distance_km']]) }}</dd>
                </div>
            </dl>

            <ul class="mt-4 flex flex-col gap-2">
                @foreach ($quote['lines'] as $line)
                    <li class="flex flex-wrap items-center justify-between gap-2 border-t border-subtle pt-2">
                        <span>{{ $line['label'] }}</span>
                        <span class="font-medium">{{ $line['amount'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-app.card>
    @endif
</div>
