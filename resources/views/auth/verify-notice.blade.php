<x-layouts.app :title="__('ui.verification.title')">
    <div class="mx-auto w-full max-w-md">
        <x-app.card :title="__('ui.verification.title')">
            @if (session('status'))
                <x-app.alert variant="success" class="mb-4">{{ session('status') }}</x-app.alert>
            @endif

            <p>{{ __('ui.verification.notice') }}</p>

            <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                @csrf
                <x-app.button type="submit">{{ __('ui.actions.resend_verification') }}</x-app.button>
            </form>
        </x-app.card>
    </div>
</x-layouts.app>
