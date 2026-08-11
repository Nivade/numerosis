<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the route a redirect payment method returns to.
 *
 * Deliberately thin: ownership of the checkout is established by
 * {@see \Nvade\Numerosis\Actions\Billing\Checkout\ResolveSetupIntent},
 * which every checkout path shares.
 */
class CheckoutReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'setup_intent' => ['required', 'string'],
        ];
    }

    public function setupIntentId(): string
    {
        return $this->string('setup_intent')->toString();
    }
}
