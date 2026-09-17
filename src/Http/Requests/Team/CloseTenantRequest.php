<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Validation\Rule;
use Nvade\Numerosis\Models\Central\Tenant;

#[ErrorBag('closeTenant')]
class CloseTenantRequest extends TenantOwnerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = tenant();

        $rules = [
            'name' => ['required', 'string', Rule::in([$tenant instanceof Tenant ? (string) $tenant->name : ''])],
            'acknowledge_balance' => ['sometimes', 'boolean'],
        ];

        $password = $this->currentPasswordRule();

        if ($password !== null) {
            $rules['password'] = $password;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.in' => __('That is not the name of this workspace.'),
        ];
    }

    public function acknowledgesOutstandingBalance(): bool
    {
        return $this->boolean('acknowledge_balance');
    }

    protected function ability(): string
    {
        return 'manageClosure';
    }
}
