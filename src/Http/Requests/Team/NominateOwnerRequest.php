<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Membership;

/**
 * No `#[RedirectToRoute]`: in path identification mode the tenant group is
 * prefixed `{tenant}` and nothing registers a URL default for that parameter,
 * so a named tenant route throws `UrlGenerationException`.
 */
#[ErrorBag('ownershipTransfer')]
class NominateOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->target();

        return $membership instanceof Membership && Gate::allows('transferOwnership', $membership);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['membership' => ['required', 'integer']];

        // `current_password` in the form rather than `password.confirm.if-set`
        // on the route, which redirects to `route('password.confirm')` and
        // throws UrlGenerationException on a `{tenant}`-prefixed group.
        if (filled($this->user()?->getAuthPassword())) {
            $rules['password'] = ['required', 'string', 'current_password:'.Context::Tenant->guard()];
        }

        return $rules;
    }

    public function target(): ?Membership
    {
        return Membership::query()->find($this->integer('membership'));
    }
}
