<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models;

use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Database\Factories\UserFactory;
use Nvade\Numerosis\Support\Compat\FilamentHasTenantsContract;
use Nvade\Numerosis\Support\Compat\FilamentUserContract;
use Nvade\Numerosis\Support\Compat\HasOneTimePasswordsIfInstalled;
use Nvade\Numerosis\Support\Compat\Tenancy\Syncable;
use Override;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $global_id
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @mixin Model
 */
// #[Fillable([
//    'name',
//    'email',
//    'password',
//    'global_id',
//    'last_seen_at',
//    'email_verified_at',
// ])]
// #[Guarded([
//    'id',
// ])]
// #[Hidden([
//    'password',
//    'remember_token',
// ])]
// #[WithoutTimestamps]
abstract class User extends Authenticatable implements FilamentHasTenantsContract, FilamentUserContract, MustVerifyEmail, Syncable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasOneTimePasswordsIfInstalled;
    use HasRoles;
    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_seen_at' => 'datetime',
        ];
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at?->gt(now()->subMinutes(5)) ?? false;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return array<int, Central\Tenant>|Collection<int, Central\Tenant>
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return GetTenantsByGlobalId::run($this->global_id);
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return GetTenantsByGlobalId::run($this->global_id)->contains('id', $tenant->getKey());
    }

    /**
     * Send the email verification notification.
     */
    #[Override]
    public function sendEmailVerificationNotification(): void
    {
        resolve(SendsEmailVerificationNotification::class)->send($this);
    }
}
