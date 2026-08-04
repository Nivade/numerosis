<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Controllers\Socialite;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class RedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_includes_return_url_in_session(): void
    {
        $returnUrl = 'https://example.com/profile';

        $response = $this->get(route('oauth', [
            'driver' => 'google',
            'return_url' => $returnUrl,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('socialite_context.return_url', $returnUrl);
    }

    public function test_it_includes_invitation_in_session(): void
    {
        $invitation = 'test-invitation-id';
        $tenant = 'test-tenant-id';

        $response = $this->get(route('oauth', [
            'driver' => 'google',
            'invitation' => $invitation,
            'tenant' => $tenant,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('socialite_context.invitation', $invitation);
        $response->assertSessionHas('socialite_context.tenant', $tenant);
        $response->assertSessionHas('socialite_context.intent', 'accept_invitation');
    }

    public function test_it_includes_both_return_url_and_invitation_in_session(): void
    {
        $returnUrl = 'https://example.com/profile';
        $invitation = 'test-invitation-id';
        $tenant = 'test-tenant-id';

        $response = $this->get(route('oauth', [
            'driver' => 'google',
            'return_url' => $returnUrl,
            'invitation' => $invitation,
            'tenant' => $tenant,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('socialite_context.return_url', $returnUrl);
        $response->assertSessionHas('socialite_context.invitation', $invitation);
        $response->assertSessionHas('socialite_context.tenant', $tenant);
        $response->assertSessionHas('socialite_context.intent', 'accept_invitation');
    }
}
