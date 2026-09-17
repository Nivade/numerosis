<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Consent;
use Nvade\Numerosis\Numerosis;

/**
 * Append-only. Withdrawing or re-granting writes another row, because the
 * question a regulator asks is what was agreed at the time, not what is
 * agreed now.
 *
 * @method static Consent run(CentralUser $user, string $purpose = 'terms')
 */
class RecordConsent
{
    use AsAction;

    public function handle(CentralUser $user, string $purpose = 'terms'): Consent
    {
        /** @var Consent $consent */
        $consent = Numerosis::model(Consent::class)::query()->create([
            'global_user_id' => $user->global_id,
            'purpose' => $purpose,
            'terms_version' => Config::string('numerosis.privacy.terms_version', '1.0'),
            'ip_address' => Request::ip(),
            'granted_at' => now(),
        ]);

        return $consent;
    }
}
