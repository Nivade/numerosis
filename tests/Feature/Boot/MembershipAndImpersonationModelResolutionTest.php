<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Boot;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\FindMembershipForUser;
use Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Tests\Support\OverriddenImpersonationSession;
use Nvade\Numerosis\Tests\Support\OverriddenMembership;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Both models joined `numerosis.models` in the same phase that routed their
 * bare `::query()` call sites through `Numerosis::model()`. One call site per
 * model stands in for the rest: the resolution mechanism is the same at
 * every one of them.
 */
class MembershipAndImpersonationModelResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([ImpersonationFeature::class]);

        parent::setUp();
    }

    public function test_find_membership_for_user_resolves_through_the_configured_override(): void
    {
        Numerosis::resetModelCache();
        Config::set('numerosis.models.'.Membership::class, OverriddenMembership::class);

        try {
            $tenant = Tenant::factory()->create();
            $user = CentralUser::factory()->create();

            $tenant->users()->attach($user->global_id, ['role' => 'member']);

            $found = FindMembershipForUser::run($tenant->id, $user->global_id);

            $this->assertInstanceOf(OverriddenMembership::class, $found);
        } finally {
            Config::set('numerosis.models.'.Membership::class);
            Numerosis::resetModelCache();
        }
    }

    public function test_get_current_impersonation_resolves_through_the_configured_override(): void
    {
        Numerosis::resetModelCache();
        Config::set('numerosis.models.'.ImpersonationSession::class, OverriddenImpersonationSession::class);

        try {
            $session = ImpersonationSession::factory()->redeemed()->create();

            session([SessionKey::ImpersonationSession->value => $session->id]);

            $found = GetCurrentImpersonation::run();

            $this->assertInstanceOf(OverriddenImpersonationSession::class, $found);
        } finally {
            Config::set('numerosis.models.'.ImpersonationSession::class);
            Numerosis::resetModelCache();
        }
    }
}
