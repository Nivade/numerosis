<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Override;

/**
 * Sanctum's token, in the tenant database, with an optional egress allowlist.
 *
 * A token belongs to a tenant user, which is what makes it per workspace: the
 * same person in two tenants holds two tokens with their own abilities, and
 * losing a membership takes the token in that workspace with it.
 *
 * @property array<int, string>|null $ip_allowlist
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 */
#[Table(name: 'personal_access_tokens')]
class ApiToken extends PersonalAccessToken
{
    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        /** @var array<string, string> $casts */
        $casts = parent::casts();

        return [...$casts, 'ip_allowlist' => 'array'];
    }

    /** An empty allowlist is unrestricted; a populated one is exhaustive. */
    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->ip_allowlist ?? [];

        if ($allowed === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
