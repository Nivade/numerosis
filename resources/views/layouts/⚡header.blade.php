<?php

use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * The header is a central-domain layout, so it always reads the central
     * guard rather than whichever guard the current context defaults to.
     */
    #[Computed]
    public function user(): ?CentralUser
    {
        /** @var ?CentralUser */
        return GetAuthenticatedUser::run(Config::string('auth.defaults.guards.context.central'));
    }
};
?>
<flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left"/>
    <a wire:navigate
       href="{{ \Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\AccountPagesFeature::NAME) ? route('tenants.mine') : route(\Nvade\Numerosis\Support\Routes\RouteNames::home()) }}"
       class="ms-2 me-5 flex items-center space-x-2 rtl:space-x-reverse lg:ms-0"
    >
        <x-numerosis::app-logo/>
    </a>
    @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\MarketingPagesFeature::NAME))
        <flux:navbar class="-mb-px max-lg:hidden">
            <flux:navbar.item icon="bolt" :href="route('features')" :current="request()->routeIs('features')"
                              wire:navigate>
                {{ __('Features') }}
            </flux:navbar.item>
        </flux:navbar>
    @endif
    <flux:spacer/>
    <flux:navbar class="me-1.5 space-x-0.5 rtl:space-x-reverse py-0!">
        @guest
            <flux:navbar.item
                class="h-10 max-lg:hidden"
                href="{{ route('login') }}"
                icon="arrow-left-end-on-rectangle"
                :label="__('Sign In')"
            >
                {{ __('Sign in') }}
            </flux:navbar.item>
        @endguest
        <flux:tooltip :content="__('Repository')" position="bottom">
            <flux:navbar.item
                class="h-10 max-lg:hidden [&>div>svg]:size-5"
                icon="folder-git-2"
                href="https://github.com/laravel/livewire-starter-kit"
                target="_blank"
                :label="__('Repository')"
            />
        </flux:tooltip>
        <flux:tooltip :content="__('Documentation')" position="bottom">
            <flux:navbar.item
                class="h-10 max-lg:hidden [&>div>svg]:size-5"
                icon="book-open-text"
                href="https://laravel.com/docs/starter-kits#livewire"
                target="_blank"
                label="Documentation"
            />
        </flux:tooltip>
    </flux:navbar>

    @island('desktop-menu')
    <!-- Desktop User Menu -->
    @auth
        <flux:dropdown position="bottom" align="end">
            <flux:profile
                class="cursor-pointer"
                :initials="$this->user->initials()"
            />
            <flux:menu :keep-open="true">
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                <span
                                    class="flex h-full w-full items-center justify-center rounded-lg bg-zinc-200 text-black dark:bg-zinc-700 dark:text-white"
                                >
                                    {{ $this->user->initials() }}
                                </span>
                            </span>
                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <span class="truncate font-semibold">{{ $this->user->name }}</span>
                                <span class="truncate text-xs">{{ $this->user->email }}</span>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>
                <flux:menu.separator/>
                @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\AccountPagesFeature::NAME))
                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog"
                                        wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>
                @endif
                @if($this->user->tenants->isNotEmpty())
                    <x-numerosis::ui.accordion>
                        <x-numerosis::ui.accordion.item name="tenants">
                            <x-numerosis::ui.accordion.heading icon="building-office">
                                {{ __('Tenants') }}
                            </x-numerosis::ui.accordion.heading>
                            <x-numerosis::ui.accordion.content>
                                <div class="space-y-0.5 ps-4">
                                    @foreach ($this->user->tenants as $tenant)
                                        @php($route = tenant_route($tenant->primaryDomain()->getHost(), 'home'))
                                        <flux:menu.item :href="$route" :target="str_starts_with($route, 'http') ? '_blank' : '_self'">
                                            {{ $tenant->name }}
                                        </flux:menu.item>
                                    @endforeach
                                </div>
                            </x-numerosis::ui.accordion.content>
                        </x-numerosis::ui.accordion.item>
                    </x-numerosis::ui.accordion>
                @endif
                <flux:menu.separator/>
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    @endauth
    @endisland

    @island('mobile-menu')
    <!-- Mobile Menu -->
    <flux:sidebar stashable sticky
                  class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>
        <a href="{{ \Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\AccountPagesFeature::NAME) ? route('tenants.mine') : route(\Nvade\Numerosis\Support\Routes\RouteNames::home()) }}" class="ms-1 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
            <x-numerosis::app-logo/>
        </a>
        <flux:navlist variant="outline">
            @auth
                @if($this->user->tenants->isNotEmpty())
                    <flux:navlist.group :heading="__('Tenants')">
                        @foreach ($this->user->tenants as /** @var \Nvade\Numerosis\Models\Central\Tenant */ $tenant)
                            @php($route = tenant_route($tenant->id, 'home'))
                            <flux:navlist.item :href="$route" :target="str_starts_with($route, 'http') ? '_blank' : '_self'">
                                {{ $tenant->name }}
                            </flux:navlist.item>
                        @endforeach
                    </flux:navlist.group>
                @endif
            @endauth
        </flux:navlist>
        <flux:spacer/>
        <flux:navlist variant="outline">
            <flux:navlist.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                {{ __('Repository') }}
            </flux:navlist.item>
            <flux:navlist.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                {{ __('Documentation') }}
            </flux:navlist.item>
        </flux:navlist>
        @guest
            <flux:navlist variant="outline">
                <flux:navlist.item :href="route('login')" icon="user" wire:navigate>
                    {{ __('Sign In') }}
                </flux:navlist.item>
            </flux:navlist>
        @endguest
    </flux:sidebar>
    @endisland
</flux:header>
