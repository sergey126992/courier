<x-layouts.app :title="__('ui.forms.reset_password_title')">
    <div class="mx-auto w-full max-w-md">
        <livewire:auth.reset-password-form :token="$token" :email="$email" />
    </div>
</x-layouts.app>
