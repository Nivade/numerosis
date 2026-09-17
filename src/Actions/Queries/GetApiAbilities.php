<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;

/**
 * The abilities a token may carry, as `context.action` pairs over the permission
 * vocabulary that already exists. Deliberately not a second vocabulary: a scope
 * a reviewer cannot map onto a permission is a scope nobody can reason about.
 *
 * Only read actions are offered while the API is read-only, so a token cannot be
 * granted a write ability that no endpoint honours — a granted ability nothing
 * enforces reads as a promise.
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
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function only(array $requested): array
    {
        return array_values(array_intersect(self::run(), $requested));
    }
}
