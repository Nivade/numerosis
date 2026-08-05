@php use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser; @endphp
<div class="text-sm">
    @if (! \Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser::run()->hasVerifiedEmail())
        <div class="flex items-center gap-x-3">
            <p class="text-gray-600 dark:text-gray-400">
                Your email address is unverified.
            </p>

            <flux:button wire:click="resendVerificationEmail" variant="subtle" size="sm">
                Click here to re-send the verification email.
            </flux:button>
        </div>
    @endif
</div>
