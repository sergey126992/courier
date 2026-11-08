@php
    $events = $this->eventTypes;
    $timezones = $this->timezoneOptions;
    $busyTargets = 'save,reload,startTelegramBinding,unbindTelegram,refreshBinding';
@endphp

<div
    class="flex flex-col gap-4"
    x-data
    x-on:dialog-confirmed.window="if ($event.detail.name === 'unbind-telegram') $wire.unbindTelegram()"
    wire:loading.attr="aria-busy"
    wire:target="{{ $busyTargets }}"
>
    {{-- Результат действия объявляется: у `alert` есть role и aria-live (FR-UI-410). --}}
    <div class="min-h-12">
        @if (filled($message))
            <x-app.alert :variant="$messageVariant">{{ $message }}</x-app.alert>
        @endif
    </div>

    @if (! $serviceAvailable)
        <x-app.alert variant="error" :title="__('ui.notifications.service_unavailable.title')">
            <p>{{ __('ui.notifications.service_unavailable.description') }}</p>

            <div class="mt-3">
                <x-app.button
                    variant="secondary"
                    type="button"
                    wire:click="reload"
                    wire:loading.attr="disabled"
                    wire:target="reload"
                >{{ __('ui.actions.refresh') }}</x-app.button>
            </div>
        </x-app.alert>
    @else
        <form wire:submit="save" class="flex flex-col gap-4" novalidate>
            <x-app.card
                :title="__('ui.notifications.matrix.title')"
                :subtitle="__('ui.notifications.matrix.subtitle')"
            >
                @if ($events === [])
                    <p class="text-sm text-muted">{{ __('ui.notifications.matrix.empty') }}</p>
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($events as $event)
                            <x-app.field
                                :name="'rows.'.$event['index'].'.channels'"
                                :id="'event-'.$event['index']"
                                class="rounded-lg border border-subtle bg-surface p-3"
                            >
                                <fieldset class="flex min-w-0 flex-col gap-1">
                                    <legend class="field-label">{{ $event['label'] }}</legend>

                                    @foreach ($event['channels'] as $channel)
                                        <x-app.checkbox
                                            :name="'event-'.$event['index'].'-'.$channel['value']"
                                            wire:model="rows.{{ $event['index'] }}.channels.{{ $channel['value'] }}"
                                            :checked="$channel['checked']"
                                            :label="$channel['label']"
                                        />
                                    @endforeach
                                </fieldset>
                            </x-app.field>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-muted">{{ __('ui.notifications.matrix.critical_hint') }}</p>
                @endif
            </x-app.card>

            <x-app.card
                :title="__('ui.notifications.quiet.title')"
                :subtitle="__('ui.notifications.quiet.subtitle')"
            >
                <div class="flex flex-col gap-4">
                    <x-app.checkbox
                        name="quietEnabled"
                        wire:model="quietEnabled"
                        :checked="$quietEnabled"
                        :label="__('ui.notifications.quiet.toggle')"
                    />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-app.field name="quietFrom" :label="__('ui.notifications.quiet.from')">
                            <x-app.input
                                name="quietFrom"
                                type="time"
                                wire:model="quietFrom"
                                :value="$quietFrom"
                            />
                        </x-app.field>

                        <x-app.field name="quietTo" :label="__('ui.notifications.quiet.to')">
                            <x-app.input
                                name="quietTo"
                                type="time"
                                wire:model="quietTo"
                                :value="$quietTo"
                            />
                        </x-app.field>

                        <x-app.field name="quietTimezone" :label="__('ui.notifications.quiet.timezone')">
                            <x-app.select
                                name="quietTimezone"
                                wire:model="quietTimezone"
                                :options="$timezones"
                                :value="$quietTimezone"
                                :placeholder="__('ui.notifications.quiet.timezone_placeholder')"
                            />
                        </x-app.field>
                    </div>

                    <p class="text-sm text-muted">{{ __('ui.notifications.quiet.hint') }}</p>
                </div>
            </x-app.card>

            <div>
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ __('ui.actions.save') }}
                </x-app.button>
            </div>
        </form>

        <x-app.card :title="__('ui.notifications.telegram.title')">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-muted">{{ __('ui.notifications.telegram.status_label') }}</span>

                    <x-app.status-badge
                        :status="$telegramStatus"
                        :label="__('ui.notifications.telegram.status.'.$telegramStatus)"
                    />
                </div>

                @if (filled($telegramVerifiedAt))
                    <p class="text-sm">{{ __('ui.notifications.telegram.verified_at', ['moment' => $telegramVerifiedAt]) }}</p>
                @endif

                <p class="text-sm text-muted">{{ __('ui.notifications.telegram.hint') }}</p>

                @if (filled($deepLink))
                    <div class="flex flex-col gap-1">
                        <x-app.link :href="$deepLink">{{ __('ui.notifications.telegram.open_bot') }}</x-app.link>

                        @if (filled($deepLinkExpiresAt))
                            <p class="text-xs text-muted">{{ __('ui.notifications.telegram.expires', ['moment' => $deepLinkExpiresAt]) }}</p>
                        @endif
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <x-app.button
                        type="button"
                        wire:click="startTelegramBinding"
                        wire:loading.attr="disabled"
                        wire:target="startTelegramBinding"
                    >{{ $telegramStatus === 'pending' ? __('ui.notifications.telegram.rebind') : __('ui.notifications.telegram.bind') }}</x-app.button>

                    <x-app.button
                        variant="secondary"
                        type="button"
                        wire:click="refreshBinding"
                        wire:loading.attr="disabled"
                        wire:target="refreshBinding"
                    >{{ __('ui.notifications.telegram.refresh') }}</x-app.button>

                    @if ($telegramStatus === 'verified' || $telegramStatus === 'pending')
                        <x-app.button
                            variant="danger"
                            type="button"
                            x-on:click="$dispatch('open-modal', 'unbind-telegram')"
                        >{{ __('ui.notifications.telegram.unbind') }}</x-app.button>
                    @endif
                </div>
            </div>

            <x-app.confirm-dialog
                name="unbind-telegram"
                :title="__('ui.notifications.telegram.unbind_title')"
                :confirm-label="__('ui.notifications.telegram.unbind')"
            >
                <p class="text-sm">{{ __('ui.notifications.telegram.unbind_description') }}</p>
            </x-app.confirm-dialog>
        </x-app.card>
    @endif
</div>
