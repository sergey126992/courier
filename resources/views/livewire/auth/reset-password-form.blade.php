<div>
    <x-app.card :title="__('ui.forms.reset_password_title')">
        <form wire:submit="reset" class="flex flex-col gap-4" novalidate>
            <x-app.field name="email" :label="__('ui.forms.email')" required>
                <x-app.input
                    name="email"
                    type="email"
                    autocomplete="username"
                    wire:model="email"
                    readonly
                />
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

            <div>
                <x-app.button type="submit" wire:loading.attr="disabled" wire:target="reset">
                    {{ __('ui.forms.submit_reset_password') }}
                </x-app.button>
            </div>
        </form>
    </x-app.card>
</div>
