<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetPendingInvitationsForTenant;
use Nvade\Numerosis\Actions\Queries\GetTenantMembers;
use Nvade\Numerosis\Actions\Queries\GetTenantSeatUsage;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\Tenant;

new #[Layout('numerosis-layouts::app')]
class extends Component
{
    public Collection $members;

    public Collection $invitations;

    public bool $invitationsEnabled = false;

    public int $seatsUsed = 0;

    public ?int $seatLimit = null;

    public bool $isOwner = false;

    /** Scalars, not the model: Livewire re-queries a hydrated model on the default connection. */
    public ?array $nomination = null;

    public function mount(): void
    {
        $tenant = tenant();
        $tenantId = (string) $tenant->getKey();

        $this->invitationsEnabled = FeatureRegistry::enabled(InvitationsFeature::NAME);
        $this->members = GetTenantMembers::run($tenantId);

        $this->isOwner = $this->members
            ->contains(fn ($membership): bool => $membership->isOwner()
                && $membership->global_user_id === auth()->user()?->global_id);

        $pending = $this->isOwner
            ? OwnershipNomination::query()->pending()->where('tenant_id', $tenantId)->with('nominee:id,global_id,name,email')->first()
            : null;

        $this->nomination = $pending === null ? null : [
            'ulid' => $pending->ulid,
            'nominee' => $pending->nominee?->email,
            'expires' => $pending->expires_at->diffForHumans(),
        ];
        $this->invitations = $this->invitationsEnabled
            ? GetPendingInvitationsForTenant::run($tenantId)
            : new Collection;

        if ($tenant instanceof Tenant) {
            $seats = GetTenantSeatUsage::run($tenant);

            $this->seatsUsed = $seats->used();
            $this->seatLimit = $seats->limit;
        }
    }
}; ?>
<section class="mx-auto max-w-prose w-full h-full content-center">
    <x-slot:title>{{ __('Team') }}</x-slot:title>
    <div class="flex w-full flex-1 flex-col gap-4">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('Team') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('Who is in this team, and who has been invited') }}</x-numerosis::ui.subheading>

            @if ($seatLimit !== null)
                <x-numerosis::ui.text variant="subtle" size="sm" class="mt-1">
                    {{ __(':used of :limit seats used', ['used' => $seatsUsed, 'limit' => $seatLimit]) }}
                </x-numerosis::ui.text>
            @endif
        </div>

        @if (session('status'))
            <x-numerosis::ui.text variant="default" size="sm">{{ session('status') }}</x-numerosis::ui.text>
        @endif

        <flux:error name="member" bag="teamMembers" />
        <flux:error name="role" bag="memberRole" />

        <x-numerosis::ui.card :padding="false">
            <x-numerosis::ui.list>
                @foreach ($members as $membership)
                    <x-numerosis::ui.list.item class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <x-numerosis::ui.text variant="default" size="sm" class="font-medium truncate">
                                {{ $membership->user?->name }}
                            </x-numerosis::ui.text>
                            <x-numerosis::ui.text variant="subtle" size="xs" class="mt-0.5 truncate">
                                {{ $membership->user?->email }}
                                @if ($membership->joined_at)
                                    &middot; {{ __('joined :when', ['when' => $membership->joined_at->diffForHumans()]) }}
                                @endif
                                @if ($membership->inviter)
                                    &middot; {{ __('invited by :name', ['name' => $membership->inviter->name]) }}
                                @endif
                            </x-numerosis::ui.text>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @can('update', $membership)
                                {{-- `url()->current()`, not `route('team.members.update')`:
                                     path mode prefixes the tenant group `{tenant}` and
                                     nothing registers a URL default for it. --}}
                                <form method="POST" action="{{ url()->current().'/members/'.$membership->getKey() }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')

                                    <flux:select name="role" size="sm" onchange="this.form.submit()">
                                        @foreach (MembershipRole::assignable() as $role)
                                            <option value="{{ $role->value }}" @selected($membership->role === $role)>{{ $role->label() }}</option>
                                        @endforeach
                                    </flux:select>
                                </form>
                            @else
                                <x-numerosis::ui.badge>{{ $membership->role->label() }}</x-numerosis::ui.badge>
                            @endcan

                            @can('delete', $membership)
                                <form method="POST" action="{{ url()->current().'/members/'.$membership->getKey() }}">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button type="submit" variant="danger" size="sm">
                                        {{ $membership->global_user_id === auth()->user()?->global_id ? __('Leave team') : __('Remove') }}
                                    </flux:button>
                                </form>
                            @endcan
                        </div>
                    </x-numerosis::ui.list.item>
                @endforeach
            </x-numerosis::ui.list>

            <x-numerosis::ui.text variant="subtle" size="xs" class="px-4 py-3">
                {{ __('Removing a member revokes their access; their records stay.') }}
            </x-numerosis::ui.text>
        </x-numerosis::ui.card>

        @if ($isOwner)
            <div>
                <x-numerosis::ui.heading :level="2">{{ __('Ownership') }}</x-numerosis::ui.heading>
                <x-numerosis::ui.subheading class="mt-1">{{ __('Ownership moves once the person you nominate accepts it.') }}</x-numerosis::ui.subheading>
            </div>

            <x-numerosis::ui.card>
                <flux:error name="membership" bag="ownershipTransfer" />

                @if ($nomination !== null)
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <x-numerosis::ui.text variant="subtle" size="sm">
                            {{ __('Waiting for :email to accept, expires :when', ['email' => $nomination['nominee'], 'when' => $nomination['expires']]) }}
                        </x-numerosis::ui.text>

                        <form method="POST" action="{{ url()->current().'/ownership/'.$nomination['ulid'] }}">
                            @csrf
                            @method('DELETE')
                            <flux:button type="submit" variant="danger" size="sm">{{ __('Revoke') }}</flux:button>
                        </form>
                    </div>
                @else
                    @php($eligible = $members->filter(fn ($membership) => ! $membership->isOwner() && $membership->joined_at !== null))

                    @if ($eligible->isEmpty())
                        <x-numerosis::ui.text variant="subtle" size="sm">
                            {{ __('Ownership can only move to a member who has accepted their invitation.') }}
                        </x-numerosis::ui.text>
                    @else
                        <form method="POST" action="{{ url()->current().'/ownership' }}" class="flex flex-col gap-4 sm:flex-row sm:items-start">
                            @csrf

                            <div class="flex-1">
                                <flux:select name="membership" :label="__('New owner')">
                                    @foreach ($eligible as $membership)
                                        <option value="{{ $membership->getKey() }}">{{ $membership->user?->name }} ({{ $membership->user?->email }})</option>
                                    @endforeach
                                </flux:select>
                            </div>

                            @if (filled(auth()->user()?->getAuthPassword()))
                                <div class="flex-1">
                                    <flux:input name="password" :label="__('Your password')" type="password" />
                                    <flux:error name="password" bag="ownershipTransfer" />
                                </div>
                            @endif

                            <flux:button type="submit" variant="danger" class="sm:mt-6">{{ __('Transfer ownership') }}</flux:button>
                        </form>
                    @endif
                @endif
            </x-numerosis::ui.card>
        @endif

        @if ($invitationsEnabled)
            <div>
                <x-numerosis::ui.heading :level="2">{{ __('Invitations') }}</x-numerosis::ui.heading>
            </div>

            <x-numerosis::ui.card>
                {{-- `bag`, not `error-bag`. flux:input has no error-bag prop, so
                     the earlier spelling rendered a stray HTML attribute and
                     StoreInvitationRequest's #[ErrorBag('inviteMember')] messages
                     reached no element at all. --}}
                <form method="POST" action="{{ url()->current().'/invitations' }}" class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    @csrf

                    <div class="flex-1">
                        <flux:input name="email" :label="__('Email')" type="email" value="{{ old('email') }}" />
                        <flux:error name="email" bag="inviteMember" />
                    </div>

                    <div class="w-40">
                        <flux:select name="role" :label="__('Role')">
                            @foreach (MembershipRole::assignable() as $role)
                                <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="role" bag="inviteMember" />
                    </div>

                    <flux:button type="submit" variant="primary" class="sm:mt-6">{{ __('Send invitation') }}</flux:button>
                </form>
            </x-numerosis::ui.card>

            <x-numerosis::ui.card :padding="false">
                @if ($invitations->isEmpty())
                    <x-numerosis::ui.empty-state
                        icon="envelope"
                        :title="__('No pending invitations')"
                        :description="__('Invite someone using the form above.')"
                    />
                @else
                    <x-numerosis::ui.list>
                        @foreach ($invitations as $invitation)
                            <x-numerosis::ui.list.item class="flex flex-row items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <x-numerosis::ui.text variant="default" size="sm" class="font-medium truncate">
                                        {{ $invitation->email }}
                                    </x-numerosis::ui.text>
                                    <x-numerosis::ui.text variant="subtle" size="xs" class="mt-0.5 truncate">
                                        {{ $invitation->role->label() }} &middot; {{ __('expires :when', ['when' => $invitation->expires_at->diffForHumans()]) }}
                                    </x-numerosis::ui.text>
                                </div>

                                <form method="POST" action="{{ url()->current().'/invitations/'.$invitation->getRouteKey() }}">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button type="submit" variant="danger" size="sm">{{ __('Revoke') }}</flux:button>
                                </form>
                            </x-numerosis::ui.list.item>
                        @endforeach
                    </x-numerosis::ui.list>
                @endif
            </x-numerosis::ui.card>
        @endif
    </div>
</section>
