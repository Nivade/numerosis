<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Cashier\Invoice;
use Nvade\Numerosis\Concerns\Billing\Billable;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Database\Factories\Central\TenantFactory;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Observers\TenantObserver;
use Nvade\Numerosis\Policies\TenantPolicy;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Nvade\Numerosis\Support\Cache\GlobalCache;
use Nvade\Numerosis\Support\Numerosis;
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
    'name',
])]
#[ObservedBy(TenantObserver::class)]
#[UsePolicy(TenantPolicy::class)]
class Tenant extends BaseTenant implements Subscribable, TenantWithDatabase
{
    use Billable;
    use HasDatabase;
    use HasDomains;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /** Clears the cached domain lookup when a domain or tenant changes. */
    use InvalidatesResolverCache;

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
     * Every other real column on `tenants`. `data` is excluded because it is
     * the store itself. Nothing is memoized while the table is missing, so
     * the first call after `migrate` sees the real schema.
     *
     * @return list<string>
     */
    private static function introspectedColumns(): array
    {
        if (self::$introspectedColumns !== null) {
            return self::$introspectedColumns;
        }

        $connection = Schema::connection(Config::string('tenancy.database.central_connection', 'central'));

        if (! $connection->hasTable('tenants')) {
            return [];
        }

        return self::$introspectedColumns = array_values(array_diff(
            $connection->getColumnListing('tenants'),
            ['data', ...self::KNOWN_COLUMNS],
        ));
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
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

    /** The user who created this tenant and owns its subscription. */
    public function owner(): ?CentralUser
    {
        return $this->users()
            ->wherePivot('role', MembershipRole::Owner->value)
            ->first();
    }

    public function primaryDomain(): ?Domain
    {
        return GlobalCache::store()->remember(
            CacheKeys::tenantPrimaryDomain($this->id),
            now()->addHour(),
            fn () => $this->domains()
                ->orderByDesc('created_at')
                ->limit(1)
                ->first()
        );
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

        $admin = $this->run(fn ($tenant) => $userClass::role('admin')->first());

        return $admin instanceof User ? $admin : null;
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
