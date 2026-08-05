<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Tests\TestCase;

class BotBlockingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_in_tenant_guard_blocks_real_user_sso(): void
    {
        $id = 'test-bot-'.uniqid();
        $domain = $this->tenantDomain($id);

        // 1. Create a tenant
        $tenant = Tenant::create(['id' => $id, 'data' => ['name' => 'Test Tenant']]);
        $tenant->domains()->create([
            'id' => $id,
            'domain' => $domain,
        ]);

        // 2. Create a central user
        $centralUser = CentralUser::create([
            'global_id' => 'real-user-global-id',
            'name' => 'Real User',
            'email' => 'real@example.com',
            'password' => 'password',
        ]);

        // Associate central user with tenant
        $centralUser->tenants()->attach($tenant, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);

        // 3. Create a bot and a real user in the tenant's database
        $tenant->run(function () {
            // No need to create users manually if they are seeded or if we just want to test SSO
            // But wait, the SSO needs the user to exist in the tenant DB.
            // If they are synced, they might already exist.

            // Let's check if the user already exists (it might have been synced automatically)
            TenantUser::updateOrCreate(
                ['global_id' => 'bot-global-id'],
                [
                    'name' => 'Chat Bot',
                    'email' => 'bot-'.uniqid().'@example.com',
                    'is_bot' => true,
                ]
            );

            TenantUser::updateOrCreate(
                ['global_id' => 'real-user-global-id'],
                [
                    'name' => 'Real User',
                    'email' => 'real-'.uniqid().'@example.com',
                    'is_bot' => false,
                ]
            );
        });

        // 4. Simulate a situation where the BOT is already authenticated in the 'tenant' guard
        // This could happen if a background job or some other logic incorrectly set the session
        $tenant->run(function () {
            $bot = TenantUser::where('is_bot', true)->first();
            Auth::guard('tenant')->login($bot);
            $this->assertTrue(Auth::guard('tenant')->check());
            $this->assertTrue(Auth::guard('tenant')->user()->is_bot);
        });

        // 5. Authenticate the REAL central user on 'web' guard
        Auth::guard('web')->login($centralUser);

        // 6. Access the tenant admin panel
        // If the bug exists, the Authenticate middleware will see that someone (the bot)
        // is already logged into the 'tenant' guard and will NOT log in the real user.
        $response = $this->actingAs($centralUser, 'web')
            ->get('http://'.$domain.'/');

        // 7. Assertions
        // If the bot is logged in, and the bot doesn't have access (or is just not the right user),
        // we might get a 403 or 404.
        // In the issue description, it says 404.
        $tenant->run(function () use ($centralUser) {
            $currentUser = Auth::guard('tenant')->user();

            // This is what we expect to FAIL if the bug is present
            // If the bug is present, $currentUser will be the bot.
            $this->assertNotNull($currentUser, 'User should be authenticated');
            $this->assertFalse($currentUser->is_bot, 'Authenticated user should NOT be a bot');
            $this->assertEquals($centralUser->global_id, $currentUser->global_id, 'Authenticated user should match the central user');
        });
    }
}
