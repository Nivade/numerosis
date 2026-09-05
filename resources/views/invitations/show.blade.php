<x-numerosis-layouts::auth :title="__('Team invitation')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header
            :title="__('You have been invited')"
            :description="__('You have been invited to join :tenant as a :role.', ['tenant' => $invitation->tenant->name, 'role' => $invitation->role->label()])"
        />

        {{-- Plain form, not Livewire: the accept POST must reach a real route
             so `throttle:` covers it and no server-side state can be forged
             through a Livewire method call. --}}
        <form method="POST" action="{{ url()->full() }}">
            @csrf

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Accept invitation') }}
            </flux:button>
        </form>
    </div>
</x-numerosis-layouts::auth>
