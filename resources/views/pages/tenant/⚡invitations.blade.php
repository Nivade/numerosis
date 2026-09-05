<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Support\Numerosis;

new #[Layout('layouts::app')]
class extends Component
{
    public Collection $invitations;

    public function mount(): void
    {
        $this->refreshInvitations();
    }

    private function refreshInvitations(): void
    {
        $invitationClass = Numerosis::model(Invitation::class);

        $this->invitations = $invitationClass::query()
            ->pending()
            ->where('tenant_id', tenant()->getKey())
            ->latest()
            ->get();
    }
}; ?>
<section class="mx-auto max-w-prose w-full h-full content-center">
    <x-slot:title>{{ __('Team Invitations') }}</x-slot:title>
    <div class="flex w-full flex-1 flex-col gap-4">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('Team Invitations') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('Invite new members to this tenant') }}</x-numerosis::ui.subheading>
        </div>

        @if (session('status'))
            <x-numerosis::ui.text variant="default" size="sm">{{ session('status') }}</x-numerosis::ui.text>
        @endif

        <x-numerosis::ui.card>
            {{-- `url()->current()`, not `route('team.invitations.store')`.
                 In path identification mode the tenant group is prefixed
                 `{tenant}`, nothing registers a URL default for it, and the
                 named route throws UrlGenerationException. The store route
                 shares this page's URI in every mode.

                 `bag`, not `error-bag`. flux:input has no error-bag prop, so
                 the earlier spelling rendered a stray HTML attribute and
                 StoreInvitationRequest's #[ErrorBag('inviteMember')] messages
                 reached no element at all. --}}
            <form method="POST" action="{{ url()->current() }}" class="flex flex-col gap-4 sm:flex-row sm:items-start">
                @csrf

                <div class="flex-1">
                    <flux:input name="email" :label="__('Email')" type="email" value="{{ old('email') }}" />
                    <flux:error name="email" bag="inviteMember" />
                </div>

                <div class="w-40">
                    <flux:select name="role" :label="__('Role')">
                        @foreach (\Nvade\Numerosis\Enums\Tenancy\MembershipRole::assignable() as $role)
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

                            <form method="POST" action="{{ url()->current().'/'.$invitation->getRouteKey() }}">
                                @csrf
                                @method('DELETE')
                                <flux:button type="submit" variant="danger" size="sm">{{ __('Revoke') }}</flux:button>
                            </form>
                        </x-numerosis::ui.list.item>
                    @endforeach
                </x-numerosis::ui.list>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
