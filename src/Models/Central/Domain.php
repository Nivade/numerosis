<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Observers\Tenancy\DomainObserver;
use Stancl\Tenancy\Database\Concerns\InvalidatesTenantsResolverCache;

/**
 * @property string $id
 * @property string $domain
 * @property string $tenant_id
 * @property-read string $url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 *
 * @mixin Model
 */
#[WithoutIncrementing]
#[ObservedBy(DomainObserver::class)]
#[DateFormat('Y-m-d H:i:s.u')]
class Domain extends \Stancl\Tenancy\Database\Models\Domain
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * Invalidates DomainTenantResolver's cache (see
     * TenancyServiceProvider::register()) when this domain is created,
     * renamed, or deleted.
     */
    use InvalidatesTenantsResolverCache;

    protected $keyType = 'string';

    /**
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Request::getScheme().'://'.$this->domain
        );
    }

    /**
     * Only ever reconstructs `{id}.{apex}` under subdomain mode, where
     * `domain` is exactly that concatenation and `getHost()` predates having
     * a stored value to just return. Every other mode's `domain` column
     * already holds the real host.
     *
     * @see \Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain
     */
    public function getHost(): string
    {
        return IdentificationMode::current() === IdentificationMode::Subdomain
            ? $this->id.'.'.Config::string('numerosis.domains.apex')
            : $this->domain;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function default(Builder $query): void
    {
        $query->limit(1);
    }
}
