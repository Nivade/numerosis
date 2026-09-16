<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Team;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Membership;

/**
 * No `#[RedirectToRoute]`: in path identification mode the tenant group is
 * prefixed `{tenant}` and nothing registers a URL default for that parameter,
 * so a named tenant route throws `UrlGenerationException`. The default
 * failure redirect is `url()->previous()`, correct in all three modes.
 */
#[ErrorBag('memberRole')]
class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof Membership && Gate::allows('update', $membership);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(MembershipRole::class)->only(MembershipRole::assignable())],
        ];
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from($this->string('role')->toString());
    }
}
