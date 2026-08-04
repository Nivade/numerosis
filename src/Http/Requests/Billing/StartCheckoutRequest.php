<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Billing;

use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StartCheckoutRequest extends FormRequest
{
    /**
     * The start-checkout route previously accepted any global_id in the
     * query string, so one user could reserve a domain in another user's
     * name. The success handler already checked this; this closes the gap.
     */
    public function authorize(): bool
    {
        return $this->input('global_id') === $this->user()?->global_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'domain' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // The 'string' rule above already reports a non-string, so
                    // there is nothing useful to add here.
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        resolve(TenantDomainPolicy::class)->assertAvailable($value);
                    } catch (ValidationException $e) {
                        $fail($e->validator->errors()->first('domain'));
                    }
                },
            ],
            'global_id' => ['required', 'string', 'exists:users,global_id'],
            'payment_plan' => ['nullable', 'string', 'exists:central.payment_plans,slug'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
        ];
    }

    public function toRegistrationData(): TenantRegistrationData
    {
        return TenantRegistrationData::from($this->validated());
    }
}
