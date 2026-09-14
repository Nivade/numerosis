<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Spatie\LaravelData\Data;

/**
 * What a tenant is to be provisioned with: its identity, plus whatever anyone
 * contributed.
 *
 * Identity is the only thing named here. Everything else, the package's own
 * billing and custom-domain data included, arrives as a
 * {@see ProvisionContribution}.
 */
class TenantProvisionData extends Data
{
    /**
     * @param  list<ProvisionContribution>  $contributions
     */
    public function __construct(
        // Becomes tenants.id and the subdomain label, never a domain itself.
        public string $slug,
        public string $name,
        public array $contributions = [],
    ) {}

    /**
     * Read back off the row the slug in Stripe's metadata resolves to,
     * contributions included.
     */
    public static function fromProvision(TenantProvision $provision): self
    {
        return new self(
            slug: $provision->slug,
            name: $provision->name,
            contributions: $provision->allContributions(),
        );
    }

    /**
     * @template TContribution of ProvisionContribution
     *
     * @param  class-string<TContribution>  $contribution
     * @return TContribution|null
     */
    public function contribution(string $contribution): ?ProvisionContribution
    {
        foreach ($this->contributions as $candidate) {
            if ($candidate instanceof $contribution) {
                /** @var TContribution */
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Replaces by class rather than appending: `contribution()` returns the
     * first match, so an appended second `BillingContribution` would be
     * shadowed by the one already there and silently ignored.
     *
     * @param  list<ProvisionContribution>  $contributions
     */
    public function withContributions(array $contributions): self
    {
        $replaced = array_map(
            static fn (ProvisionContribution $c): string => $c::class,
            $contributions,
        );

        $kept = array_filter(
            $this->contributions,
            static fn (ProvisionContribution $c): bool => ! in_array($c::class, $replaced, true),
        );

        return new self(
            slug: $this->slug,
            name: $this->name,
            contributions: [...array_values($kept), ...$contributions],
        );
    }

    /**
     * Only `name`: it's validated identically wherever it's collected.
     * `slug` is deliberately not here — the registration wizard checks
     * availability against `tenant_provisions` before checkout exists, while
     * checkout checks it through
     * {@see \Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy} against the
     * tenant that's about to be created; same field, different rules by design.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
