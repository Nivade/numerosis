<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-1.5 text-xs text-zinc-400 dark:text-zinc-500']) }}>
    <flux:icon.shield-check class="size-3.5 shrink-0" />
    <span>{{ __('numerosis::billing.checkout.secure_notice') }}</span>
</div>
