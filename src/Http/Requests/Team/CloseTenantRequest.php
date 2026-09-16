<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Nvade\Numerosis\Actions\Queries\FindMembershipForUser;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;

/**
 * No `#[RedirectToRoute]`: in path identification mode the tenant group is
 * prefixed `{tenant}` and nothing registers a URL default for that parameter,
 * so a named tenant route throws `UrlGenerationException`.
 */
#[ErrorBag('closeTenant')]
class CloseTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->membership();

        return $membership instanceof Membership && Gate::allows('manageClosure', $membership);
    }

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

        // `current_password` in the form rather than `password.confirm.if-set`
        // on the route, which redirects to `route('password.confirm')` and
        // throws UrlGenerationException on a `{tenant}`-prefixed group.
        if (filled($this->user()?->getAuthPassword())) {
            $rules['password'] = ['required', 'string', 'current_password:'.Context::Tenant->guard()];
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

    public function membership(): ?Membership
    {
        $tenant = tenant();
        $user = $this->user();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return FindMembershipForUser::run(
            (string) $tenant->getTenantKey(),
            $user instanceof User ? $user->global_id : null,
        );
    }

    public function acknowledgesOutstandingBalance(): bool
    {
        return $this->boolean('acknowledge_balance');
    }
}
