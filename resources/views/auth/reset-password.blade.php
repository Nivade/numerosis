<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <flux:input
                name="email"
                :value="old('email', $request->query('email'))"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <x-numerosis::auth.password-fields />

            <x-numerosis::turnstile-field />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
