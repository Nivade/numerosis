<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Nvade\Numerosis\Data\Auth\DeviceSession;

/**
 * Reads and revokes the stored sessions of one central user. Point
 * `numerosis.tenancy.implementations` at your own class to back it with Redis
 * or anything else that can index sessions by user.
 */
interface SessionRegistry
{
    /** Whether the configured session driver can enumerate sessions at all. */
    public function listable(): bool;

    /** @return list<DeviceSession> */
    public function forUser(string $guard, int|string $userId): array;

    public function forget(string $guard, int|string $userId, string $sessionId): bool;

    /** @return int<0, max> */
    public function forgetOthers(string $guard, int|string $userId, ?string $exceptSessionId): int;

    /**
     * Strips one tenant's guard state from every session of this user, leaving
     * their central session and their other tenants untouched.
     *
     * @return int<0, max>
     */
    public function forgetTenantAccess(string $guard, int|string $userId, string $tenantId): int;
}
