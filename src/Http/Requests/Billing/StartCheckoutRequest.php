<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Billing;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;

class StartCheckoutRequest extends FormRequest
{
    /**
     * Refuses a checkout started in someone else's name — the request names
     * the user it is for, so it has to match whoever is signed in.
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
