<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Privacy;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\Consent;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Without a record of what was agreed and when, the lawful basis for holding
 * any of the rest of this cannot be shown.
 */
class ConsentRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_records_the_terms_version_in_force(): void
    {
        Config::set('numerosis.privacy.terms_version', '2026-09');

        $email = 'consenting-'.uniqid().'@example.test';

        $this->post('/register', [
            'name' => 'Consenting User',
            'email' => $email,
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect();

        $user = CentralUser::firstWhere('email', $email);

        $this->assertNotNull($user);

        $consent = Consent::query()->where('global_user_id', $user->global_id)->firstOrFail();

        $this->assertSame('terms', $consent->purpose);
        $this->assertSame('2026-09', $consent->terms_version);
        $this->assertNotNull($consent->granted_at);
    }
}
