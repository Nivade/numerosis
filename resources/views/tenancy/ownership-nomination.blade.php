<x-numerosis-layouts::auth :title="__('Ownership transfer')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header
            :title="__('Take over :tenant', ['tenant' => $nomination->tenant->name])"
            :description="__('Accepting makes you the owner of :tenant and puts its subscription and billing details in your name.', ['tenant' => $nomination->tenant->name])"
        />

        {{-- Plain form, not Livewire: the accept POST must reach a real route
             so `throttle:` covers it and no server-side state can be forged
             through a Livewire method call. --}}
        <form method="POST" action="{{ url()->full() }}">
            @csrf

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Accept ownership') }}
            </flux:button>
        </form>
    </div>
</x-numerosis-layouts::auth>
