@php
    $senderProfile = $user->senderProfile;
    $isSender = $user->role->hasSenderProfile();
    $canEdit = $user->can('updateProfile', $user);
    $address = is_array($senderProfile?->default_address) ? $senderProfile->default_address : [];
    $value = static fn (string $key): ?string => filled($address[$key] ?? null) ? (string) $address[$key] : null;
@endphp

<x-layouts.app :title="__('ui.profile.title')">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
        @if (session('status'))
            <x-app.alert variant="success">{{ session('status') }}</x-app.alert>
        @endif

        <x-app.card :title="__('ui.profile.title')">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.profile.name') }}</dt>
                    <dd class="text-sm">{{ $user->name }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.profile.email') }}</dt>
                    <dd class="text-sm">{{ $user->email }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.profile.role') }}</dt>
                    <dd class="text-sm">{{ $user->role->label() }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('ui.profile.email_status') }}</dt>
                    <dd class="text-sm">
                        {{ $user->hasVerifiedEmail() ? __('ui.profile.email_verified') : __('ui.profile.email_unverified') }}
                    </dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                @if (! $user->hasVerifiedEmail())
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <x-app.button variant="secondary" type="submit">{{ __('ui.actions.resend_verification') }}</x-app.button>
                    </form>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-app.button variant="quiet" type="submit">{{ __('ui.nav.logout') }}</x-app.button>
                </form>
            </div>

            {{-- FR-UI-118: честная подпись о том, чего на этом этапе ещё нет. --}}
            <p class="mt-4 text-sm text-muted">{{ __('ui.profile.future_screens') }}</p>
        </x-app.card>

        {{-- FR-UI-401, FR-UI-411: вход на экран настроек — карточка-ссылка профиля. --}}
        <x-app.card :title="__('ui.notifications.title')">
            <p class="text-sm text-muted">{{ __('ui.notifications.entry.description') }}</p>

            <div class="mt-3">
                <x-app.link :href="route('account.notifications')">{{ __('ui.notifications.entry.link') }}</x-app.link>
            </div>
        </x-app.card>

        @if ($isSender && $canEdit)
            <x-app.card :title="__('ui.profile.sender_title')">
                <form method="POST" action="{{ route('account.update') }}" class="flex flex-col gap-4" novalidate>
                    @csrf

                    <x-app.field name="phone" :label="__('ui.profile.phone')">
                        <x-app.input name="phone" type="tel" autocomplete="tel" :value="$senderProfile?->phone" />
                    </x-app.field>

                    <x-app.field name="company" :label="__('ui.profile.company')">
                        <x-app.input name="company" autocomplete="organization" :value="$senderProfile?->company" />
                    </x-app.field>

                    <fieldset class="flex flex-col gap-3">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-muted">
                            {{ __('ui.profile.address') }}
                        </legend>

                        <x-app.field name="default_address.district" id="default-address-district" :label="__('ui.profile.address_district')">
                            <x-app.input
                                name="default_address[district]"
                                id="default-address-district"
                                :value="$value('district')"
                            />
                        </x-app.field>

                        <x-app.field name="default_address.street" id="default-address-street" :label="__('ui.profile.address_street')">
                            <x-app.input
                                name="default_address[street]"
                                id="default-address-street"
                                :value="$value('street')"
                            />
                        </x-app.field>

                        <x-app.field name="default_address.house" id="default-address-house" :label="__('ui.profile.address_house')">
                            <x-app.input
                                name="default_address[house]"
                                id="default-address-house"
                                :value="$value('house')"
                            />
                        </x-app.field>
                    </fieldset>

                    <div>
                        <x-app.button type="submit">{{ __('ui.actions.save') }}</x-app.button>
                    </div>
                </form>
            </x-app.card>
        @endif
    </div>
</x-layouts.app>
