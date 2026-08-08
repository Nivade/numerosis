<div class="{{ $embedded ? '' : 'max-w-3xl mx-auto py-12 px-4' }} space-y-8">
    @unless($embedded)
        <div class="text-center space-y-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Complete Your Subscription</h1>
            <p class="text-zinc-500 dark:text-zinc-400">Add a payment method to activate your workspace.</p>
        </div>
    @endunless

    <x-numerosis::billing.payment-error :message="$paymentError" />

    @if($checkoutClientSecret && $checkoutPublishableKey)
        <div
            x-data="stripeCheckout(@js($checkoutClientSecret), @js($checkoutPublishableKey), @js(route('checkout.subscription.return')), @js(__('numerosis::billing.decline_codes')), @js($customerEmail), @js($savedBillingAddress), @js($savedPaymentMethods))"
            x-init="init()"
            class="space-y-8"
        >
            @if($savedBillingFetchFailed)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('numerosis::billing.checkout.saved_billing_fetch_failed') }}
                </flux:callout>
            @endif

            @if($savedPaymentMethodsFetchFailed)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('numerosis::billing.checkout.saved_payment_methods_fetch_failed') }}
                </flux:callout>
            @elseif(! empty($savedPaymentMethods))
                <div class="space-y-3">
                    <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ __('numerosis::billing.checkout.saved_payment_methods_heading') }}
                    </flux:heading>

                    <div class="space-y-2">
                        @foreach($savedPaymentMethods as $pm)
                            <x-numerosis::billing.saved-payment-method-option :pm="$pm" :checked="$loop->first" />
                        @endforeach

                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-4 text-zinc-500 dark:text-zinc-400 transition-colors hover:border-zinc-400 dark:hover:border-zinc-600 hover:text-zinc-700 dark:hover:text-zinc-200 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary has-[:focus-visible]:ring-offset-2">
                            <input type="radio" name="pm-choice" @change="mode = 'new'" class="sr-only" />
                            <flux:icon.plus variant="micro" class="size-4 shrink-0" />
                            <span class="text-sm font-medium">{{ __('numerosis::billing.checkout.use_different_payment_method') }}</span>
                        </label>
                    </div>

                    <div x-show="mode === 'saved'" class="flex justify-end pt-2">
                        <flux:button
                            type="button"
                            x-on:click="submitSaved(selectedPaymentMethodId)"
                            x-bind:disabled="isSubmitting"
                            variant="primary"
                        >
                            <span x-show="!isSubmitting">{{ __('numerosis::billing.checkout.subscribe') }}</span>
                            <span x-show="isSubmitting" x-cloak>{{ __('numerosis::billing.checkout.processing') }}</span>
                        </flux:button>
                    </div>
                </div>
            @endif

            <div x-show="mode === 'new'" class="space-y-8">
                <x-numerosis::billing.payment-element />
                <x-numerosis::billing.address-element />

                <div class="flex justify-end pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button
                        type="button"
                        x-on:click="submit()"
                        x-bind:disabled="isSubmitting || !elementReady || !addressElementReady"
                        variant="primary"
                    >
                        <span x-show="!isSubmitting">{{ __('numerosis::billing.checkout.subscribe') }}</span>
                        <span x-show="isSubmitting" x-cloak>{{ __('numerosis::billing.checkout.processing') }}</span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
