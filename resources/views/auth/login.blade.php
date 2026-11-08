<x-layouts.app :title="__('ui.forms.login_title')">
    <div class="mx-auto w-full max-w-md">
        {{-- Сообщение после сброса пароля: «Пароль изменён» (FR-UI-107). --}}
        @if (session('status'))
            <x-app.alert variant="success" class="mb-4">{{ session('status') }}</x-app.alert>
        @endif

        <livewire:auth.login-form />

        <p class="mt-4 text-sm">
            <x-app.link :href="route('password.request')" variant="quiet">{{ __('ui.forms.forgot_password') }}</x-app.link>
        </p>
    </div>
</x-layouts.app>
