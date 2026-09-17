<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;
use Livewire\Wireable;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * A promotion code Stripe has just confirmed is usable. Carries what Stripe
 * said about the discount and nothing computed here: a locally derived
 * discounted amount is a number that eventually disagrees with the invoice.
 */
class PromotionData extends Data implements Wireable
{
    use WireableData;

    public function __construct(
        public string $code,
        public string $promotion_code_id,
        public string $coupon_id,
        public ?int $percent_off = null,
        public ?int $amount_off = null,
        public ?string $currency = null,
        public ?string $duration = null,
        public ?int $duration_in_months = null,
        public ?Carbon $expires_at = null,
    ) {}

    /** What to show beside the code, in Stripe's own terms. */
    public function label(): string
    {
        if ($this->percent_off !== null) {
            return "{$this->percent_off}% off";
        }

        return $this->amount_off === null
            ? $this->code
            : Cashier::formatAmount($this->amount_off, $this->currency).' off';
    }

    /**
     * How long it lasts, when Stripe says it ends. `forever` and `once` are
     * Stripe's own vocabulary and are left as they are.
     */
    public function durationLabel(): ?string
    {
        return match ($this->duration) {
            'forever' => 'for as long as the subscription runs',
            'once' => 'on the first invoice',
            'repeating' => $this->duration_in_months === null
                ? null
                : "for {$this->duration_in_months} month(s)",
            default => null,
        };
    }
}
