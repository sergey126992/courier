<div>
    <x-app.card :title="__('ui.forms.login_title')">
        <form wire:submit="login" class="flex flex-col gap-4" novalidate>
            <x-app.field name="email" :label="__('ui.forms.email')" required>
                <x-app.input name="email" type="email" autocomplete="email" wire:model="email" />
            </x-app.field>

            <x-app.field name="password" :label="__('ui.forms.password')" required>
                <x-app.input
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    wire:model="password"
                />
            </x-app.field>

            <x-app.checkbox name="remember" :label="__('ui.forms.remember')" wire:model="remember" />

            <div>
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="login">
                    {{ __('ui.forms.submit_login') }}
                </x-app.button>
            </div>
        </form>
    </x-app.card>
</div>
