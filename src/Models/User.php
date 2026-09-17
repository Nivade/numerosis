<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Override;
use Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Contracts\Syncable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string $password
 * @property string $global_id
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $anonymized_at
 * @property string|null $remember_token
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @mixin Model
 */
abstract class User extends Authenticatable implements MustVerifyEmail, Syncable
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasOneTimePasswords;
    use HasRoles;
    use Notifiable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return GetTenantsByGlobalId::run($this->global_id)->contains('id', $tenant->getKey());
    }

    #[Override]
    public function sendEmailVerificationNotification(): void
    {
        // Nothing to send to: the provider this account registered through
        // returned no address.
        if ($this->email === null) {
            return;
        }

        resolve(SendsEmailVerificationNotification::class)->send($this);
    }
}
