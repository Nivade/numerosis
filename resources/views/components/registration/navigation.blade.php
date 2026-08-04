@props([
    'backAction' => 'back',
    'continueAction' => 'continue',
    'continueLabel' => 'Continue',
    'continueIcon' => 'chevron-right',
    'isSubmitting' => false,
    'disabled' => false,
    'showBack' => true,
    'loading' => null,
])

<div {{ $attributes->merge(['class' => 'flex justify-between items-center pt-8 border-t border-gray-200 dark:border-zinc-700']) }}>
    @if($showBack)
        <flux:button
            wire:click="{{ $backAction }}"
            variant="outline"
            icon="chevron-left"
        >
            Back
        </flux:button>
    @else
        <div></div>
    @endif

    <flux:button
        wire:click="{{ $continueAction }}"
        variant="primary"
        :icon-trailing="$continueIcon"
        class="px-8 bg-linear-to-r from-blue-600 to-purple-600 border-none"
        :loading="$loading ?? $continueAction"
        :disabled="$disabled"
    >
        {{ $continueLabel }}
    </flux:button>
</div>
