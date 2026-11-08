@php
    $titleKey = $alreadyVerified ? 'ui.verification.already_verified_title' : 'ui.verification.verified_title';
    $textKey = $alreadyVerified ? 'ui.verification.already_verified' : 'ui.verification.verified';
@endphp

<x-layouts.app :title="__($titleKey)">
    <div class="mx-auto w-full max-w-md">
        <x-app.card :title="__($titleKey)">
            <x-app.alert variant="success">{{ __($textKey) }}</x-app.alert>

            <p class="mt-4 text-sm">
                <x-app.link :href="route('login')" variant="quiet">{{ __('ui.actions.back_to_login') }}</x-app.link>
            </p>
        </x-app.card>
    </div>
</x-layouts.app>
