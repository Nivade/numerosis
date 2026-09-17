<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy\Domains;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\DnsResolver;
use Nvade\Numerosis\Data\Tenancy\DomainVerificationResult;
use Nvade\Numerosis\Models\Central\Domain;

/**
 * Reads DNS and says which half of the proof holds: the TXT record carrying the
 * domain's token, and the CNAME or A record that makes traffic arrive here.
 *
 * Decides nothing about state — {@see RecordDomainVerification} owns the
 * transition — so a caller can check a domain without writing to it.
 *
 * @method static DomainVerificationResult run(Domain $domain)
 */
class VerifyDomainOwnership
{
    use AsAction;

    public function __construct(private readonly DnsResolver $dns) {}

    public function handle(Domain $domain): DomainVerificationResult
    {
        $token = $domain->verification_token;

        if ($token === null || $token === '') {
            return DomainVerificationResult::missingToken();
        }

        $values = $this->dns->txt($domain->challengeHost());

        if ($values === []) {
            return DomainVerificationResult::missingToken();
        }

        if (! in_array($token, $values, true)) {
            return DomainVerificationResult::tokenMismatch();
        }

        return DomainVerificationResult::proven($this->pointedHere($domain->domain));
    }

    /**
     * Either the CNAME names the configured target, or an A record matches one
     * of the configured addresses — an apex zone often cannot carry a CNAME.
     */
    private function pointedHere(string $host): bool
    {
        $target = Config::get('numerosis.tenancy.custom_domains.cname_target')
            ?? Config::get('numerosis.domains.central');

        if (is_string($target) && $target !== '') {
            $expected = strtolower(rtrim($target, '.'));

            if (in_array($expected, $this->dns->cname($host), true)) {
                return true;
            }
        }

        $accepted = array_values(array_filter(
            Config::array('numerosis.tenancy.custom_domains.a_records', []),
            is_string(...),
        ));

        if ($accepted === []) {
            return false;
        }

        return array_intersect($accepted, $this->dns->addresses($host)) !== [];
    }
}
