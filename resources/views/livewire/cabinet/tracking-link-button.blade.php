<div class="flex flex-col gap-3" x-data="{ copied: false }">
    <div class="min-h-12">
        @if (filled($error))
            <x-app.alert variant="error">{{ $error }}</x-app.alert>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-app.button type="button" wire:click="create" wire:loading.attr="disabled" wire:target="create">
            {{ __('ui.actions.create_tracking_link') }}
        </x-app.button>

        @if (filled($url))
            {{-- Копирование без анимаций: при reduced-motion поведение не меняется (FR-UI-177). --}}
            <x-app.button
                variant="secondary"
                type="button"
                x-on:click="navigator.clipboard.writeText(@js($url)).then(() => copied = true)"
            >
                {{ __('tracking.labels.copy_link') }}
            </x-app.button>
        @endif
    </div>

    <p role="status" aria-live="polite" class="text-sm text-muted">
        <span x-show="copied">{{ __('tracking.messages.copied') }}</span>
    </p>

    @if (filled($url) && filled($expiresAt))
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-1">
                <dt class="text-xs text-muted">{{ __('tracking.labels.public_title') }}</dt>
                <dd class="min-w-0">
                    <x-app.link :href="$url" target="_blank" rel="noopener noreferrer" class="list-value text-sm break-all">
                        {{ $url }}
                    </x-app.link>
                </dd>
            </div>

            <div class="flex flex-col gap-1">
                <dt class="text-xs text-muted">{{ __('tracking.labels.expires_at') }}</dt>
                <dd class="text-sm">{{ $expiresAt }}</dd>
            </div>
        </dl>
    @endif
</div>
