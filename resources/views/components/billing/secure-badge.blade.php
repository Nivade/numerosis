<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-1.5 text-xs text-gray-400 dark:text-zinc-500']) }}>
    <flux:icon.shield-check class="size-3.5 shrink-0" />
    <span>{{ __('billing.checkout.secure_notice') }}</span>
</div>
