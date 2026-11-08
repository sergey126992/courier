<div>
    <x-app.card :title="__('ui.forms.register_title')">
        <form wire:submit="register" class="flex flex-col gap-4" novalidate>
            <x-app.field name="name" :label="__('ui.forms.name')" required>
                <x-app.input name="name" type="text" autocomplete="name" wire:model="name" />
            </x-app.field>

            <x-app.field name="email" :label="__('ui.forms.email')" required>
                <x-app.input name="email" type="email" autocomplete="email" wire:model="email" />
            </x-app.field>

            <x-app.field name="password" :label="__('ui.forms.password')" required>
                <x-app.input
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    wire:model="password"
                />
            </x-app.field>

            <x-app.field name="password_confirmation" :label="__('ui.forms.password_confirmation')" required>
                <x-app.input
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    wire:model="password_confirmation"
                />
            </x-app.field>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="register">
                    {{ __('ui.forms.submit_register') }}
                </x-app.button>

                <x-app.link :href="route('login')" variant="quiet">{{ __('ui.nav.login') }}</x-app.link>
            </div>
        </form>
    </x-app.card>
</div>
