<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorises the return route a redirect-flavoured payment
 * method (iDEAL, Bancontact) bounces back to. Deliberately thin: the
 * ownership check lives in ResolveSetupIntent, shared with the Payment
 * step's subscribe(), so it cannot drift between the two entry points.
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
