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
 * @method static list<string> run()
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
    ];

    private const array READ_ACTIONS = [
        PermissionAction::ViewAny,
        PermissionAction::View,
    ];

    /**
     * @return list<string>
     */
    public function handle(): array
    {
        $abilities = [];

        foreach (self::CONTEXTS as $context) {
            foreach (self::READ_ACTIONS as $action) {
                $abilities[] = self::ability($context, $action);
            }
        }

        return $abilities;
    }

    public static function ability(PermissionContext $context, PermissionAction $action): string
    {
        return $context->value.'.'.$action->value;
    }

    /**
     * What this person may mint. Defence in depth, not the enforcement: the
     * endpoints ask again per request, because a role narrowed after the token
     * was issued has to narrow the token with it.
     *
     * @return list<string>
     */
    public static function forUser(User $user): array
    {
        $gate = Gate::forUser($user);

        return array_values(array_filter(
            self::run(),
            static fn (string $ability): bool => $ability !== self::ability(PermissionContext::Subscriptions, PermissionAction::View)
                || $gate->allows('viewBilling', Membership::class),
        ));
    }
}
