<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\User;

/**
 * Only read actions are offered while the API is read-only. A granted ability
 * that no endpoint enforces reads to the grantee as a promise.
 *
 * `$user` scopes the list to what that person may currently mint. Endpoints
 * still check on every request, since a role can narrow after a token issues.
 *
 * @method static list<string> run(?User $user = null)
 */
class GetApiAbilities
{
    use AsAction;

    /** The contexts an API token can reach; the rest are central-only. */
    public const array CONTEXTS = [
        PermissionContext::Tenants,
        PermissionContext::Users,
        PermissionContext::Invitations,
        PermissionContext::Subscriptions,
        PermissionContext::Domains,
    ];

    private const array READ_ACTIONS = [
        PermissionAction::ViewAny,
        PermissionAction::View,
    ];

    /**
     * @return list<string>
     */
    public function handle(?User $user = null): array
    {
        $abilities = [];

        foreach (self::CONTEXTS as $context) {
            foreach (self::READ_ACTIONS as $action) {
                $abilities[] = $context->abilityFor($action);
            }
        }

        if (! $user instanceof User) {
            return $abilities;
        }

        $gate = Gate::forUser($user);
        $subscriptionView = PermissionContext::Subscriptions->abilityFor(PermissionAction::View);

        return array_values(array_filter(
            $abilities,
            static fn (string $ability): bool => $ability !== $subscriptionView
                || $gate->allows('viewBilling', Membership::class),
        ));
    }
}
