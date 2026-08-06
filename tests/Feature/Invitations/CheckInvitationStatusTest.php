<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Invitations;

use App\Models\Central\Tenant;
use App\Models\Tenant\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
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
     * CheckInvitationStatus redirects to the tenant root via url('/') and
     * reports the reason as a Filament notification, not a session `error`
     * key or a named route — both of which the assertions here used to expect.
     */
    /**
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     */
    protected function assertRedirectsHomeWithNotice(TestResponse $response, string $message): void
    {
        $response->assertRedirect('http://'.$this->tenantDomain($this->tenant->id));

        /** @var list<array<string, mixed>> $sessionNotifications */
        $sessionNotifications = session('filament.notifications', []);

        $notifications = collect($sessionNotifications)
            ->pluck('title')
            ->filter();

        $this->assertTrue(
            $notifications->contains($message),
            "Expected a Filament notification titled \"{$message}\", got: {$notifications->implode(', ')}"
        );
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
