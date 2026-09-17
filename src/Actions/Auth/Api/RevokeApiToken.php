<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth\Api;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Nvade\Numerosis\Models\Tenant\User;

/**
 * Deletes one of a user's own tokens. Scoped to the owner as well as the id, so
 * an id from a request body cannot revoke somebody else's token.
 *
 * @method static bool run(User $user, int|string $tokenId)
 */
class RevokeApiToken
{
    use AsAction;

    public function handle(User $user, int|string $tokenId): bool
    {
        /** @var ApiToken|null $token */
        $token = $user->tokens()->whereKey($tokenId)->first();

        if (! $token instanceof ApiToken) {
            return false;
        }

        $token->delete();

        activity()
            ->causedBy($user)
            ->withProperties(['token_id' => $tokenId, 'name' => $token->getAttribute('name')])
            ->event('api_token.revoked')
            ->log('API token revoked');

        return true;
    }
}
