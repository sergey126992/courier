@props([
    'user' => null,
])

@php
    $currentUser = $user ?? auth()->user();
    $role = $currentUser?->role;

    $homeRoute = $role !== null ? config('ui.roles.'.$role->value.'.home') : 'home';
    $brandHref = is_string($homeRoute) && \Illuminate\Support\Facades\Route::has($homeRoute)
        ? route($homeRoute)
        : url('/');

    $items = [];

    $linkFor = static function (string $routeName, string $label): ?array {
        if (! \Illuminate\Support\Facades\Route::has($routeName)) {
            return null;
        }

        return [
            'href' => route($routeName),
            'label' => $label,
            'active' => request()->routeIs($routeName),
        ];
    };

    if ($currentUser !== null) {
        if ($currentUser->can('create', \App\Models\Order\DeliveryOrder::class)) {
            foreach (['cabinet.orders' => 'ui.nav.orders', 'cabinet.orders.create' => 'ui.nav.create_order'] as $routeName => $labelKey) {
                $item = $linkFor($routeName, __($labelKey));

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        if ($currentUser->can('assign', \App\Models\Dispatch\Assignment::class)) {
            $item = $linkFor('dispatcher.queue', __('ui.nav.queue'));

            if ($item !== null) {
                $items[] = $item;
            }
        }

        // Административные страницы уведомлений (ЧТЗ 8.2, FR-NTF-293).
        if ($currentUser->can('notifications:manage')) {
            foreach (['notifications.failed' => 'ui.nav.notifications', 'notifications.push-simulator' => 'ui.nav.push_simulator'] as $routeName => $labelKey) {
                $item = $linkFor($routeName, __($labelKey));

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        $item = $linkFor('account', __('ui.nav.account'));

        if ($item !== null) {
            $items[] = $item;
        }
    }

    $logoutUrl = $currentUser !== null && \Illuminate\Support\Facades\Route::has('logout') ? route('logout') : null;
    $loginUrl = $currentUser === null && \Illuminate\Support\Facades\Route::has('login') ? route('login') : null;
    $registerUrl = $currentUser === null && \Illuminate\Support\Facades\Route::has('register') ? route('register') : null;

    $brandMark = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) config('ui.brand'), 0, 1));
    $initials = $currentUser !== null
        ? \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) $currentUser->name, 0, 1))
        : '';
@endphp

<header class="topbar">
    <a class="brand" href="{{ $brandHref }}">
        <span class="brand-mark" aria-hidden="true">{{ $brandMark }}</span>
        <span>{{ config('ui.brand') }}</span>
    </a>

    <x-app.nav :items="$items" class="hidden md:flex" />

    <div class="ml-auto flex flex-wrap items-center gap-2">
        <x-app.theme-toggle />

        @if ($currentUser !== null)
            <div class="user hidden sm:flex">
                <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="flex min-w-0 flex-col">
                    <span class="user-name">{{ $currentUser->name }}</span>
                    <span class="user-role">{{ $role?->label() }}</span>
                </span>
            </div>

            @if ($logoutUrl !== null)
                <form class="hidden md:block" method="POST" action="{{ $logoutUrl }}">
                    @csrf
                    <x-app.button variant="quiet" size="sm" type="submit">{{ __('ui.nav.logout') }}</x-app.button>
                </form>
            @endif
        @else
            @if ($loginUrl !== null)
                <x-app.link :href="$loginUrl" variant="quiet">{{ __('ui.nav.login') }}</x-app.link>
            @endif

            @if ($registerUrl !== null)
                <x-app.link :href="$registerUrl">{{ __('ui.nav.register') }}</x-app.link>
            @endif
        @endif

        <div x-data="shellDrawer('main-menu')" x-on:keydown.escape.window="close()" class="md:hidden">
            <button
                type="button"
                class="btn btn-quiet btn-sm"
                aria-controls="main-menu-panel"
                aria-expanded="false"
                aria-label="{{ __('ui.nav.open_menu') }}"
                x-bind:aria-expanded="open ? 'true' : 'false'"
                x-on:click="show()"
            ><span aria-hidden="true">☰</span></button>

            <div
                x-show="open"
                x-cloak
                class="modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="main-menu-title"
                x-on:click.self="close()"
            >
                <div
                    id="main-menu-panel"
                    class="modal-panel"
                    x-ref="panel"
                    tabindex="-1"
                    x-on:keydown.tab="trap($event)"
                >
                    <div class="modal-header">
                        <h2 class="modal-title" id="main-menu-title">{{ __('ui.nav.label') }}</h2>

                        <button
                            type="button"
                            class="btn btn-quiet btn-sm"
                            aria-label="{{ __('ui.nav.close_menu') }}"
                            x-on:click="close()"
                        ><span aria-hidden="true">&times;</span></button>
                    </div>

                    <x-app.scroll-area class="modal-body">
                        <x-app.nav :items="$items" orientation="vertical" />

                        @if ($currentUser !== null)
                            <div class="user mt-4">
                                <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                                <span class="flex min-w-0 flex-col">
                                    <span class="user-name">{{ $currentUser->name }}</span>
                                    <span class="user-role">{{ $role?->label() }}</span>
                                </span>
                            </div>

                            @if ($logoutUrl !== null)
                                <form class="mt-4" method="POST" action="{{ $logoutUrl }}">
                                    @csrf
                                    <x-app.button variant="secondary" type="submit" class="btn-block">{{ __('ui.nav.logout') }}</x-app.button>
                                </form>
                            @endif
                        @endif
                    </x-app.scroll-area>
                </div>
            </div>
        </div>
    </div>
</header>
