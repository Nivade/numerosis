<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Models\Central\Membership;
use Override;

#[ErrorBag('ownershipTransfer')]
class NominateOwnerRequest extends TenantOwnerRequest
{
    private ?Membership $resolvedTarget = null;

    /** Authorized against the nominee's row, not the sender's own. */
    #[Override]
    public function authorize(): bool
    {
        $membership = $this->target();

        return $membership instanceof Membership && Gate::allows($this->ability(), $membership);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['membership' => ['required', 'integer']];

        $password = $this->currentPasswordRule();

        if ($password !== null) {
            $rules['password'] = $password;
        }

        return $rules;
    }

    /** Memoized: `authorize()` and the controller both ask for the same row. */
    public function target(): ?Membership
    {
        return $this->resolvedTarget ??= Membership::query()->find($this->integer('membership'));
    }

    protected function ability(): string
    {
        return 'transferOwnership';
    }
}
