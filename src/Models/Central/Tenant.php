<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Cashier\Invoice;
use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Concerns\Billing\Billable;
use Nvade\Numerosis\Concerns\Tenancy\RunsInTenant;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Contracts\Tenancy\Closable;
use Nvade\Numerosis\Contracts\Tenancy\HasTenantOwner;
use Nvade\Numerosis\Contracts\Tenancy\Suspendable;
use Nvade\Numerosis\Database\Factories\Central\TenantFactory;
use Nvade\Numerosis\Enums\Auth\SystemRole;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Observers\Tenancy\TenantObserver;
use Nvade\Numerosis\Policies\Tenancy\TenantPolicy;
use Override;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Concerns\InvalidatesResolverCache;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property string $id
 * @property string $name
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $data
 * @property-read string $title
 * @property-read string $slug
 * @property-read string $initials
 * @property-read Collection<int, CentralUser> $users
 * @property-read int|null $users_count
 * @property-read Collection<int, Domain> $domains
 * @property-read int|null $domains_count
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 *
 * @mixin Model
 */
#[Fillable([
    'id',
    'data',
    'stripe_id',
    'pm_type',
    'pm_last_four',
    'trial_ends_at',
    'registration_date',
    'created_by',
    'provisioned_at',
    'suspended_at',
    'closed_at',
    'name',
])]
#[ObservedBy(TenantObserver::class)]
#[UsePolicy(TenantPolicy::class)]
class Tenant extends BaseTenant implements Closable, HasTenantOwner, Subscribable, Suspendable, TenantWithDatabase
{
    use Billable;
    use HasDatabase;
    use HasDomains;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /** Clears the cached domain lookup when a domain or tenant changes. */
    use InvalidatesResolverCache;

    use RunsInTenant;

    /**
     * The columns the package itself relies on, whether or not the `tenants`
     * table has been migrated yet.
     *
     * @var list<string>
     */
    private const array KNOWN_COLUMNS = [
        'id',
        'created_at',
        'updated_at',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'provisioned_at',
        'suspended_at',
        'closed_at',
    ];

    /** @var list<string>|null */
    private static ?array $introspectedColumns = null;

    /**
     * The attributes stored in real columns. Everything else is folded into
     * the `data` JSON column. A column added by a host's own migration is
     * picked up from the schema, so it needs no registration.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [...self::KNOWN_COLUMNS, ...self::introspectedColumns()];
    }

    /** Clears {@see self::getCustomColumns()}'s schema memoization. */
    public static function flushColumnCache(): void
    {
        self::$introspectedColumns = null;
    }

    /**
     * Every other real column on `tenants`, memoized for the request behind a
     * cache entry {@see \Nvade\Numerosis\Listeners\Tenancy\ForgetTenantColumnListing}
     * drops whenever migrations run.
     *
     * @return list<string>
     */
    private static function introspectedColumns(): array
    {
        return self::$introspectedColumns ?? self::readColumnListing() ?? [];
    }

    /**
     * Null while the table is missing, which is neither memoized nor cached so
     * the first call after `migrate` sees the real schema.
     *
     * @return list<string>|null
     */
    private static function readColumnListing(): ?array
    {
        $key = CacheKeys::tenantCustomColumns();
        $ttl = CacheTtl::tenantCustomColumns();
        $cached = $ttl === null ? null : GlobalCache::store()->get($key);

        if (is_array($cached)) {
            /** @var list<string> $cached */
            return self::$introspectedColumns = $cached;
        }

        $connection = Schema::connection(Config::string('tenancy.database.central_connection', 'central'));

        if (! $connection->hasTable('tenants')) {
            return null;
        }

        // `data` is excluded because it is the store the rest are folded into.
        $columns = array_values(array_diff(
            $connection->getColumnListing('tenants'),
            ['data', ...self::KNOWN_COLUMNS],
        ));

        if ($ttl !== null) {
            GlobalCache::store()->put($key, $columns, $ttl);
        }

        return self::$introspectedColumns = $columns;
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function purgeAt(): ?Carbon
    {
        return $this->closed_at?->copy()->addDays(Config::integer('numerosis.tenancy.closure.grace_days', 30));
    }

    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null;
    }

    /**
     * @return BelongsToMany<CentralUser, $this, Membership>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            Numerosis::model(CentralUser::class),
            'memberships',
            'tenant_id',
            'global_user_id',
            'id',
            'global_id'
        )
            ->using(Membership::class)
            ->withPivot(['role', 'invited_by', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The user who created this tenant and owns its subscription. Only the
     * `global_id` is cached; the row comes back through
     * {@see FindUserByGlobalId}, which has its own key and invalidator.
     */
    public function owner(): ?CentralUser
    {
        $globalId = GlobalCache::remember(
            CacheKeys::tenantOwnerGlobalId($this->id),
            CacheTtl::tenantOwnerGlobalId(),
            function (): ?string {
                $globalId = $this->users()
                    ->wherePivot('role', MembershipRole::Owner->value)
                    ->value('global_id');

                return is_string($globalId) ? $globalId : null;
            }
        );

        if ($globalId === null) {
            return null;
        }

        $owner = FindUserByGlobalId::run($globalId, Context::Central);

        return $owner instanceof CentralUser ? $owner : null;
    }

    /**
     * Deliberately not memoized on the instance: the global key is forgotten
     * whenever a domain is added or removed, and callers within the same
     * request are expected to see that. Attributes are cached rather than the
     * model, and "no domain" as `false`, which in path mode is every tenant.
     */
    public function primaryDomain(): ?Domain
    {
        $attributes = GlobalCache::remember(
            CacheKeys::tenantPrimaryDomain($this->id),
            CacheTtl::tenantPrimaryDomain(),
            fn (): array|false => $this->domains()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1)
                ->first()?->getAttributes() ?? false
        );

        if ($attributes === false) {
            return null;
        }

        $domain = Numerosis::model(Domain::class);

        return (new $domain)->newFromBuilder($attributes);
    }

    /**
     * Where this tenant answers, in every identification mode. Path mode
     * creates no `domains` row at all, so there the tenant hangs off the
     * central host under its own key.
     */
    public function baseUrl(): string
    {
        $domain = $this->primaryDomain();

        if ($domain instanceof Domain) {
            return Request::getScheme().'://'.$domain->getHost();
        }

        return Request::getScheme().'://'
            .Config::string('numerosis.domains.central')
            .'/'.$this->getTenantKey();
    }

    public function latestInvoice(): ?Invoice
    {
        // latestSubscription() is a method; reading it as a property throws.
        return $this->latestSubscription()?->latestInvoice([
            'download' => true,
        ]);
    }

    public function admin(): ?User
    {
        $userClass = Numerosis::model(User::class);

        $admin = $this->runHere(fn (): ?Model => $userClass::role(SystemRole::Admin->value)->first());

        return $admin instanceof User ? $admin : null;
    }

    /** {@see RunsInTenant::runInTenant()} against this tenant, which is the only one this model can mean. */
    public function runHere(Closure $callback): mixed
    {
        return $this->runInTenant($this, $callback);
    }

    /**
     * The owner's email, which is what identifies this tenant's customer in
     * the Stripe dashboard.
     */
    public function stripeEmail(): ?string
    {
        return $this->owner()?->email;
    }

    /**
     * @return Attribute<string, never>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::ucfirst($this->id)
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function slug(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::slug($this->name)
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function initials(): Attribute
    {
        return Attribute::make(
            get: fn () => collect(preg_split('/\s+/', (string) ($this->name ?? '')) ?: [])
                ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                ->take(2)
                ->implode('')
        );
    }
}
