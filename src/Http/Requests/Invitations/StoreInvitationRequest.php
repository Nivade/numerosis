<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Invitations;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Nvade\Numerosis\Data\Invitations\InvitationData;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;

/**
 * No `#[RedirectToRoute]`. In path identification mode the tenant group is
 * prefixed `{tenant}` and nothing registers a URL default for that parameter,
 * so `route('team.invitations.index')` throws `UrlGenerationException`. The
 * default failure redirect is `url()->previous()`, which is correct in all
 * three modes.
 */
#[ErrorBag('inviteMember')]
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Invitation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return InvitationData::rules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = $this->string('email')->lower()->toString();

            $tenantUserClass = Numerosis::model(TenantUser::class);

            if ($tenantUserClass::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                $validator->errors()->add('email', __('That address is already a member of this tenant.'));
            }
        });
    }

    /**
     * `from()` casts and does not validate, since `validation_strategy` is
     * `OnlyRequests` and this is an array. The rules above already ran.
     */
    public function toInvitationData(): InvitationData
    {
        return InvitationData::from([
            'email' => $this->string('email')->lower()->toString(),
            'role' => $this->string('role')->toString(),
        ]);
    }
}
