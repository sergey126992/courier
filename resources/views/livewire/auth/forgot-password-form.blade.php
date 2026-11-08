<div>
    <x-app.card :title="__('ui.forms.forgot_password_title')">
        @if ($sent)
            {{-- Ответ одинаков для существующего и несуществующего адреса (FR-UI-107). --}}
            <x-app.alert variant="info">{{ __('auth.password_reset_sent') }}</x-app.alert>
        @else
            <form wire:submit="send" class="flex flex-col gap-4" novalidate>
                <x-app.field name="email" :label="__('ui.forms.email')" required>
                    <x-app.input name="email" type="email" autocomplete="email" wire:model="email" />
                </x-app.field>

                <div>
                    <x-app.button type="submit" wire:loading.attr="disabled" wire:target="send">
                        {{ __('ui.forms.submit_forgot_password') }}
                    </x-app.button>
                </div>
            </form>
        @endif

        <p class="mt-4 text-sm">
            <x-app.link :href="route('login')" variant="quiet">{{ __('ui.actions.back_to_login') }}</x-app.link>
        </p>
    </x-app.card>
</div>
