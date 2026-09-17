<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Actions\Billing\Promotions\AcceptRetentionOffer;
use Nvade\Numerosis\Actions\Queries\FindMembershipForUser;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;

/**
 * The owner taking the offer instead of closing. Gated on the same ability as
 * the closure itself: whoever may close the workspace is whoever may be kept.
 */
class AcceptRetentionOfferController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $user = $request->user();

        abort_unless($tenant instanceof Tenant, 403);

        $membership = FindMembershipForUser::run(
            (string) $tenant->getTenantKey(),
            $user instanceof User ? $user->global_id : null,
        );

        abort_unless($membership instanceof Membership && Gate::allows('manageClosure', $membership), 403);

        $applied = AcceptRetentionOffer::run($tenant);

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}` and nothing registers a URL default for that parameter.
        return back()->with('status', $applied instanceof PromotionData
            ? __('Your discount is applied: :label.', ['label' => $applied->label()])
            : __('That offer is no longer available.'));
    }
}
