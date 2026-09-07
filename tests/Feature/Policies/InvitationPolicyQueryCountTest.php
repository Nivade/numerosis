<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Policies;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The ownership fallback in `InvitationPolicy::delete()` runs for every row a
 * non-admin sees, and used to be a per-row `exists()` against the central
 * `users` table. It reads the `invitedBy` relation now, which the listing
 * eager loads, so an N-row list costs one query rather than N.
 */
class InvitationPolicyQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ownership_fallback_does_not_query_per_row(): void
    {
        $inviter = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();

        Invitation::factory()->count(5)->for($tenant, 'tenant')->create([
            'invited_by_user_id' => $inviter->id,
        ]);

        $invitations = Invitation::query()
            ->where('tenant_id', $tenant->id)
            ->with('invitedBy:id,global_id')
            ->get();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, '`users`')) {
                $queries[] = $query->sql;
            }
        });

        foreach ($invitations as $invitation) {
            $this->assertSame($inviter->global_id, $invitation->invitedBy?->global_id);
        }

        $this->assertSame([], $queries, 'The inviter is already loaded; reading it must not query again.');
    }
}
