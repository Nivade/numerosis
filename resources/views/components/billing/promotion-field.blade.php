@props(['applied' => null, 'error' => null])

{{--
    The label is Stripe's own description of the discount, never a total this
    package worked out: a locally computed figure eventually disagrees with the
    invoice, and the invoice is what the customer is charged.
--}}
<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @if($applied)
        <div class="flex items-center justify-between rounded-lg bg-success-bg border border-success-border px-4 py-3">
            <div class="flex items-center gap-2">
                <flux:icon.ticket class="size-4 text-success-icon shrink-0" />
                <x-numerosis::ui.text size="sm" class="text-success-text">
                    {{ __('numerosis::billing.promotion.applied', ['label' => $applied->label()]) }}
                    @if($applied->durationLabel())
                        <span class="opacity-80">{{ $applied->durationLabel() }}</span>
                    @endif
                </x-numerosis::ui.text>
            </div>

            <flux:button type="button" size="xs" variant="ghost" wire:click="removePromotionCode">
                {{ __('numerosis::billing.promotion.remove') }}
            </flux:button>
        </div>
    @else
        <div class="flex items-end gap-2">
            <flux:input
                wire:model="promotionCode"
                wire:keydown.enter.prevent="applyPromotionCode"
                :label="__('numerosis::billing.promotion.label')"
                :placeholder="__('numerosis::billing.promotion.placeholder')"
                class="flex-1"
            />

            <flux:button type="button" wire:click="applyPromotionCode" wire:loading.attr="disabled">
                {{ __('numerosis::billing.promotion.apply') }}
            </flux:button>
        </div>
    @endif

    @if($error)
        <x-numerosis::ui.text size="sm" class="text-danger-text">{{ $error }}</x-numerosis::ui.text>
    @endif
</div>
