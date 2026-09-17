<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Actions\Queries\FindMembershipForUser;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;

/**
 * A form only the acting user's own owner membership may submit. Subclasses
 * name the ability and add their own fields.
 *
 * No `#[RedirectToRoute]`: in path identification mode the tenant group is
 * prefixed `{tenant}` and nothing registers a URL default for that parameter,
 * so a named tenant route throws `UrlGenerationException`.
 */
abstract class TenantOwnerRequest extends FormRequest
{
    private ?Membership $resolvedMembership = null;

    abstract protected function ability(): string;

    public function authorize(): bool
    {
        $membership = $this->membership();

        return $membership instanceof Membership && Gate::allows($this->ability(), $membership);
    }

    public function membership(): ?Membership
    {
        if ($this->resolvedMembership instanceof Membership) {
            return $this->resolvedMembership;
        }

        $tenant = tenant();
        $user = $this->user();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return $this->resolvedMembership = FindMembershipForUser::run(
            (string) $tenant->getTenantKey(),
            $user instanceof User ? $user->global_id : null,
        );
    }

    /**
     * `current_password` in the form instead of `password.confirm.if-set` on
     * the route, which redirects to `route('password.confirm')` and throws
     * `UrlGenerationException` on a `{tenant}`-prefixed group.
     *
     * @return list<string>|null
     */
    protected function currentPasswordRule(): ?array
    {
        return filled($this->user()?->getAuthPassword())
            ? ['required', 'string', 'current_password:'.Context::Tenant->guard()]
            : null;
    }
}
