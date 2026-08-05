@props(['message'])

{{--
    Deliberately distinct from flux:error. A Stripe decline is the customer's
    bank refusing, recoverable by trying another method; a validation error
    is our form complaining. The same red box for both trains people to
    ignore the one that matters — see custom-checkout.md, "UX decisions".
--}}
@if($message)
    <div {{ $attributes->merge(['class' => 'flex items-start gap-2 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 px-4 py-3']) }}>
        <flux:icon.exclamation-triangle class="size-4 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
        <x-numerosis::ui.text size="sm" class="text-red-700 dark:text-red-300">
            {{ $message }}
        </x-numerosis::ui.text>
    </div>
@endif
