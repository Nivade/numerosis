<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\Attributes\ErrorBag;

#[ErrorBag('twoFactorRequirement')]
class UpdateTwoFactorRequirementRequest extends TenantOwnerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['required' => ['required', 'boolean']];

        $password = $this->currentPasswordRule();

        if ($password !== null) {
            $rules['password'] = $password;
        }

        return $rules;
    }

    public function requirement(): bool
    {
        return $this->boolean('required');
    }

    protected function ability(): string
    {
        return 'manageSecurity';
    }
}
