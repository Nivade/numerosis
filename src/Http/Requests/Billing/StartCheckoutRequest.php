<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Billing;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\User;

class StartCheckoutRequest extends FormRequest
{
    /**
     * Refuses a checkout started in someone else's name: the request names
     * the user it is for, so it has to match whoever is signed in.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $this->input('global_id') === $user->global_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...TenantProvisionData::rules(),
            'slug' => [
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

    /**
     * Everything but the slug and the name is contributed: the owner belongs
     * to a relationship and the plan to billing, neither to what a tenant is.
     */
    public function toProvisionData(): TenantProvisionData
    {
        /** @var array{slug: string, name: string, global_id: string, payment_plan?: string|null, billing_cycle: string} $validated */
        $validated = $this->validated();

        return new TenantProvisionData(
            slug: $validated['slug'],
            name: $validated['name'],
            contributions: [
                new OwnerContribution($validated['global_id']),
                new BillingContribution(
                    payment_plan: $validated['payment_plan'] ?? null,
                    billing_cycle: BillingCycle::from($validated['billing_cycle']),
                ),
            ],
        );
    }
}
