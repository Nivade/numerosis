@props(['message'])

{{--
    Deliberately distinct from flux:error. A Stripe decline is the customer's
    bank refusing, recoverable by trying another method; a validation error
    is our form complaining. The same red box for both trains people to
    ignore the one that matters.
--}}
@if($message)
    <div {{ $attributes->merge(['class' => 'flex items-start gap-2 rounded-lg bg-danger-bg border border-danger-border px-4 py-3']) }}>
        <flux:icon.exclamation-triangle class="size-4 text-danger-icon shrink-0 mt-0.5" />
        <x-numerosis::ui.text size="sm" class="text-danger-text">
            {{ $message }}
        </x-numerosis::ui.text>
    </div>
@endif
