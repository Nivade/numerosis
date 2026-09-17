<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Auth\UserAnonymized;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Numerosis;

/**
 * Not atomic across N databases and cannot be, since each tenant is committed
 * on its own connection. Re-running finishes what a failure left, because every
 * step is keyed on `anonymized_at` being null.
 *
 * @method static bool run(CentralUser $user)
 */
class AnonymizeUser
{
    use AsAction;

    public function handle(CentralUser $user): bool
    {
        if ($user->anonymized_at !== null) {
            return true;
        }

        $globalId = (string) $user->global_id;

        /** @var list<string> $tenantIds */
        $tenantIds = $user->tenants()->pluck('tenants.id')
            ->map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all();

        foreach ($tenantIds as $tenantId) {
            $this->anonymizeInTenant($tenantId, $user);
        }

        $this->anonymizeCentrally($user, $globalId);

        event(new UserAnonymized($globalId, $tenantIds));

        return true;
    }

    /**
     * The row is kept and rewritten: deleting it would take the workspace's
     * own content with it.
     */
    private function anonymizeInTenant(string $tenantId, CentralUser $user): void
    {
        $tenant = Numerosis::model(Tenant::class)::query()->find($tenantId);

        if (! $tenant instanceof Tenant || ! $tenant->isProvisioned()) {
            return;
        }

        $globalId = (string) $user->global_id;

        $tenant->runHere(function () use ($globalId, $user): void {
            $tenantUserClass = Numerosis::model(TenantUser::class);

            /** @var list<int> $twinIds */
            $twinIds = $tenantUserClass::query()
                ->where('global_id', $globalId)
                ->whereNull('anonymized_at')
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->values()
                ->all();

            $tenantUserClass::query()
                ->where('global_id', $globalId)
                ->whereNull('anonymized_at')
                ->update($this->redactedAttributes($globalId));

            $this->scrubActivity(null, (new $tenantUserClass)->getMorphClass(), $twinIds);

            // Domain events always causedBy() the central user, never the tenant
            // twin, even for entries written to a tenant's own database.
            $this->scrubActivity(null, $user->getMorphClass(), [$user->id]);
        });
    }

    private function anonymizeCentrally(CentralUser $user, string $globalId): void
    {
        $connection = $user->getConnection();

        $connection->transaction(function () use ($user, $globalId): void {
            Numerosis::model(SocialAccount::class)::query()->where('user_id', $user->getKey())->delete();

            Numerosis::model(Membership::class)::query()->where('global_user_id', $globalId)->delete();

            $user->forceFill([
                ...$this->redactedAttributes($globalId),
                'password' => Str::password(64),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $user->delete();
        });

        $this->scrubActivity($connection->getName(), $user->getMorphClass(), [$user->id]);

        RevokeOtherSessions::run(Context::Central->guard(), $user->id, keepCurrent: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function redactedAttributes(string $globalId): array
    {
        return [
            'name' => __('Deleted user'),
            'email' => 'deleted-'.substr(hash('sha256', $globalId), 0, 24).'@anonymized.invalid',
            'email_verified_at' => null,
            'anonymized_at' => now(),
        ];
    }

    /**
     * @param  list<int>  $causerIds
     */
    private function scrubActivity(?string $connection, string $causerType, array $causerIds): void
    {
        if ($causerIds === []) {
            return;
        }

        DB::connection($connection)->table('activity_log')
            ->where('causer_type', $causerType)
            ->whereIn('causer_id', $causerIds)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($connection): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $properties */
                    $properties = json_decode((string) $row->properties, true) ?? [];

                    DB::connection($connection)->table('activity_log')
                        ->where('id', $row->id)
                        ->update([
                            'causer_id' => null,
                            'causer_type' => null,
                            'properties' => json_encode($this->scrubIdentity($properties)),
                        ]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function scrubIdentity(array $properties): array
    {
        unset($properties['name'], $properties['email']);

        foreach (['attributes', 'old'] as $key) {
            $nested = $properties[$key] ?? null;

            if (is_array($nested)) {
                unset($nested['name'], $nested['email']);
                $properties[$key] = $nested;
            }
        }

        return $properties;
    }
}
