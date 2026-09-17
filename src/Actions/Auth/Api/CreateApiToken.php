<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth\Api;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetApiAbilities;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Nvade\Numerosis\Models\Tenant\User;

/**
 * Issues a token for a tenant user, with the plaintext value returned once and
 * never stored. Abilities are filtered against {@see GetApiAbilities}: an
 * ability no endpoint enforces would read as a promise the API does not keep.
 *
 * @method static NewAccessToken run(User $user, string $name, list<string> $abilities = [], ?Carbon $expiresAt = null, list<string> $ipAllowlist = [])
 */
class CreateApiToken
{
    use AsAction;

    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $ipAllowlist
     */
    public function handle(
        User $user,
        string $name,
        array $abilities = [],
        ?Carbon $expiresAt = null,
        array $ipAllowlist = [],
    ): NewAccessToken {
        $granted = array_values(array_intersect(GetApiAbilities::forUser($user), $abilities));

        $token = $user->createToken($name, $granted, $expiresAt);

        if ($ipAllowlist !== []) {
            $accessToken = $token->accessToken;

            if ($accessToken instanceof ApiToken) {
                $accessToken->forceFill(['ip_allowlist' => array_values($ipAllowlist)])->save();
            }
        }

        activity()
            ->performedOn($token->accessToken)
            ->causedBy($user)
            ->withProperties(['abilities' => $granted, 'expires_at' => $expiresAt?->toDateTimeString()])
            ->event('api_token.created')
            ->log('API token created');

        return $token;
    }
}
