<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Auth;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Session\DatabaseSessionHandler;
use Nvade\Numerosis\Models\User;

/**
 * Stamps every session row with the signed-in person's global id, which is the
 * one identity the central and tenant guards share, so a device list can find a
 * person's rows through an index instead of unserializing every live payload.
 */
class GlobalIdSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @param-out array<array-key, mixed> $payload
     */
    protected function addUserInformation(&$payload)
    {
        parent::addUserInformation($payload);

        $payload['global_user_id'] = $this->globalUserId();

        return $this;
    }

    private function globalUserId(): ?string
    {
        $container = $this->container;

        if (! $container instanceof Container || ! $container->bound(Guard::class)) {
            return null;
        }

        $user = $container->make(Guard::class)->user();

        return $user instanceof User && is_string($user->global_id) ? $user->global_id : null;
    }
}
