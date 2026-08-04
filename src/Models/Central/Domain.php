<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Nvade\Numerosis\Observers\DomainObserver;
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

    public function getHost(): string
    {
        return $this->id.'.'.Config::string('app.host');
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
