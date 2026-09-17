<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Auth\SessionRegistry;
use Nvade\Numerosis\Data\Auth\DeviceSession;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;
use stdClass;

/**
 * Laravel's `sessions` table stores the guard's login key inside the payload
 * and only the *ambient* guard's id in `user_id`, so a tenant request writes a
 * tenant primary key there. The decoded payload therefore stays the authority
 * for what a read returns; `global_user_id` and `last_activity` only bound the
 * rows it has to decode.
 */
class DatabaseSessionRegistry implements SessionRegistry
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function listable(): bool
    {
        return Config::get('session.driver') === 'database';
    }

    public function forUser(string $guard, int|string $userId): array
    {
        if (! $this->listable()) {
            return [];
        }

        $sessions = [];

        foreach ($this->rowsFor($guard, $userId) as [$row, $payload]) {
            $tenantId = $payload[SessionKey::TenancySessionTenant->value] ?? null;

            $sessions[] = new DeviceSession(
                id: (string) $row->id,
                ipAddress: is_string($row->ip_address ?? null) ? $row->ip_address : null,
                userAgent: is_string($row->user_agent ?? null) ? $row->user_agent : null,
                lastActiveAt: CarbonImmutable::createFromTimestamp((int) $row->last_activity),
                tenantId: is_string($tenantId) ? $tenantId : null,
            );
        }

        usort($sessions, fn (DeviceSession $a, DeviceSession $b): int => $b->lastActiveAt <=> $a->lastActiveAt);

        return $sessions;
    }

    public function forget(string $guard, int|string $userId, string $sessionId): bool
    {
        if (! $this->listable()) {
            return false;
        }

        // The named row only, not the whole listable set: ownership is decided
        // from that one payload.
        $row = $this->table()->where('id', $sessionId)->first();

        if (! $row instanceof stdClass || ! $this->belongsTo($row, $guard, $userId)) {
            return false;
        }

        return $this->table()->where('id', $sessionId)->delete() > 0;
    }

    public function forgetOthers(string $guard, int|string $userId, ?string $exceptSessionId): int
    {
        if (! $this->listable()) {
            return 0;
        }

        $ids = [];

        foreach ($this->rowsFor($guard, $userId) as [$row, $payload]) {
            if ((string) $row->id !== $exceptSessionId) {
                $ids[] = $row->id;
            }
        }

        return $ids === [] ? 0 : max(0, $this->table()->whereIn('id', $ids)->delete());
    }

    public function forgetTenantAccess(string $guard, int|string $userId, string $tenantId): int
    {
        if (! $this->listable()) {
            return 0;
        }

        $tenantGuardKey = $this->guardSessionKey(Context::Tenant->guard());
        $forgotten = 0;

        foreach ($this->rowsFor($guard, $userId) as [$row, $payload]) {
            if (($payload[SessionKey::TenancySessionTenant->value] ?? null) !== $tenantId) {
                continue;
            }

            unset($payload[$tenantGuardKey], $payload[SessionKey::TenancySessionTenant->value]);

            $this->table()->where('id', $row->id)->update(['payload' => base64_encode(serialize($payload))]);

            $forgotten++;
        }

        return $forgotten;
    }

    /**
     * Rows whose payload holds this guard's login key for this user, each
     * paired with its decoded payload.
     *
     * @return list<array{0: stdClass, 1: array<array-key, mixed>}>
     */
    private function rowsFor(string $guard, int|string $userId): array
    {
        $guardKey = $this->guardSessionKey($guard);
        $since = CarbonImmutable::now()->subMinutes(Config::integer('session.lifetime'))->getTimestamp();
        $globalId = $this->globalIdOf($guard, $userId);
        $matches = [];

        // Rows written before the stamp landed carry no global id and are
        // still this person's, so they stay in the scan until they expire.
        $query = $this->table()
            ->where('last_activity', '>=', $since)
            ->where(fn (Builder $rows): Builder => $rows
                ->where('global_user_id', $globalId)
                ->orWhereNull('global_user_id'));

        foreach ($query->get() as $row) {
            if (! $row instanceof stdClass || ! is_string($row->payload ?? null)) {
                continue;
            }

            $payload = $this->decode($row->payload);

            if ($payload === null || ! $this->identifies($payload, $guardKey, $userId)) {
                continue;
            }

            $matches[] = [$row, $payload];
        }

        return $matches;
    }

    /**
     * The one identity both guards agree on: `user_id` holds whichever guard
     * was ambient when the row was written, so only this narrows the scan.
     */
    private function globalIdOf(string $guard, int|string $userId): ?string
    {
        $provider = $this->auth->guard($guard) instanceof SessionGuard
            ? $this->auth->guard($guard)->getProvider()
            : null;

        $user = $provider?->retrieveById($userId);

        return $user instanceof User && is_string($user->global_id) ? $user->global_id : null;
    }

    private function belongsTo(stdClass $row, string $guard, int|string $userId): bool
    {
        if (! is_string($row->payload ?? null)) {
            return false;
        }

        $payload = $this->decode($row->payload);

        return $payload !== null && $this->identifies($payload, $this->guardSessionKey($guard), $userId);
    }

    /** @param  array<array-key, mixed>  $payload */
    private function identifies(array $payload, string $guardKey, int|string $userId): bool
    {
        $loggedInAs = $payload[$guardKey] ?? null;

        if (! is_int($loggedInAs) && ! is_string($loggedInAs)) {
            return false;
        }

        return (string) $loggedInAs === (string) $userId;
    }

    /** @return array<array-key, mixed>|null */
    private function decode(string $payload): ?array
    {
        $raw = base64_decode($payload, true);

        if ($raw === false) {
            return null;
        }

        $decoded = @unserialize($raw);

        return is_array($decoded) ? $decoded : null;
    }

    private function guardSessionKey(string $guard): string
    {
        $resolved = $this->auth->guard($guard);

        return $resolved instanceof SessionGuard
            ? $resolved->getName()
            : 'login_'.$guard.'_'.sha1(SessionGuard::class);
    }

    private function table(): Builder
    {
        $connection = Config::get('session.connection');

        return $this->connections
            ->connection(is_string($connection) ? $connection : null)
            ->table(Config::string('session.table', 'sessions'));
    }
}
