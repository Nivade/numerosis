<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Invitations;

use App\Models\Central\Tenant;
use App\Models\Tenant\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Models\Tenant\Invitation as PackageInvitation;
use Nvade\Numerosis\Tests\TestCase;

class CheckInvitationStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Every request below is made against a tenant host, and the
        // middleware under test redirects with url('/'). Tests\TestCase
        // forces a central root URL process-wide (see its docblock — that is
        // what makes relative-URL requests resolve at all), and a forced root
        // beats the current request's, so url('/') would answer
        // http://central.numerosistest.test no matter which host asked.
        // Clearing it here restores the production behaviour this file is
        // actually asserting.
        URL::useOrigin(null);

        // forceCreate: `id` is not fillable, so create() lets UUIDGenerator
        // assign a uuid and the subdomain below would address nothing.
        $this->tenant = Tenant::forceCreate(['id' => 'test-'.uniqid()]);
        $this->tenant->domains()->create([
            'id' => $this->tenant->id,
            'domain' => $this->tenantDomain($this->tenant->id),
        ]);
    }

    public function test_it_allows_access_to_pending_invitation(): void
    {
        $token = $this->invitation([
            'expires_at' => now()->addDays(1),
            'accepted_at' => null,
        ]);

        $response = $this->getTenantRoute($token);

        $response->assertStatus(200);
    }

    public function test_it_redirects_if_invitation_is_expired(): void
    {
        $token = $this->invitation([
            'expires_at' => now()->subDays(1),
            'accepted_at' => null,
        ]);

        $response = $this->getTenantRoute($token);

        $this->assertRedirectsHomeWithNotice($response, __('This invitation has expired.'));
    }

    public function test_it_redirects_if_invitation_is_already_accepted(): void
    {
        $token = $this->invitation([
            'expires_at' => now()->addDays(1),
            'accepted_at' => now(),
        ]);

        $response = $this->getTenantRoute($token);

        $this->assertRedirectsHomeWithNotice($response, __('This invitation has already been accepted.'));
    }

    public function test_it_returns_404_for_invalid_token(): void
    {
        $response = $this->getTenantRoute('invalid-token');

        $response->assertStatus(404);
    }

    /**
     * A consumer rebinds InvitationRepository to change how invitations are
     * looked up (a different key shape, a cache in front of the query)
     * without forking this middleware. Proves the binding is real: a token
     * the real table has never seen still resolves — via the override
     * alone, since a genuine lookup would 404 first.
     */
    public function test_a_consumer_can_override_how_invitations_are_looked_up(): void
    {
        /** @var PackageInvitation $acceptedInvitation */
        $acceptedInvitation = $this->tenant->run(
            fn (): PackageInvitation => Invitation::factory()->create([
                'expires_at' => now()->addDays(1),
                'accepted_at' => now(),
            ])
        );

        $this->app->instance(InvitationRepository::class, new readonly class($acceptedInvitation) implements InvitationRepository
        {
            public function __construct(private PackageInvitation $invitation) {}

            public function findByToken(string $token): PackageInvitation
            {
                return $this->invitation;
            }

            public function findOrFailByToken(string $token): PackageInvitation
            {
                return $this->invitation;
            }

            public function find(int $id): PackageInvitation
            {
                return $this->invitation;
            }
        });

        $response = $this->getTenantRoute('this-token-was-never-persisted');

        $this->assertRedirectsHomeWithNotice($response, __('This invitation has already been accepted.'));
    }

    /**
     * CheckInvitationStatus redirects to the tenant root via url('/') and
     * flashes the reason under `error`, not a named route — which the
     * assertions here used to expect.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function assertRedirectsHomeWithNotice(TestResponse $response, string $message): void
    {
        $response->assertRedirect('http://'.$this->tenantDomain($this->tenant->id));
        $response->assertSessionHas('error', $message);
    }

    /**
     * Invitations live in the tenant database, so the row has to be written
     * inside tenant context — the middleware under test reads it from there.
     * Building it on the default connection writes to the central schema,
     * which has no `is_bot` column for the invited_by user and no invitations
     * table to find later.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function invitation(array $attributes): string
    {
        $token = $this->tenant->run(
            fn (): string => Invitation::factory()->create($attributes)->token
        );

        return is_string($token) ? $token : '';
    }

    protected function getTenantRoute(string $token)
    {
        return $this->get('http://'.$this->tenantDomain($this->tenant->id)."/invitation/{$token}");
    }
}
