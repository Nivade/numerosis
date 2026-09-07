<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Database\Factories\Central\SocialAccountFactory;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Policies\Auth\SocialAccountPolicy;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $user_id
 * @property SocialProvider $provider
 * @property string $provider_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $avatar_url
 * @property string|null $token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CentralUser $user
 *
 * @mixin Model
 */
#[Table('social_accounts')]
#[Fillable(['user_id', 'provider', 'provider_id', 'name', 'email', 'avatar_url', 'token', 'refresh_token', 'token_expires_at'])]
#[Hidden(['token', 'refresh_token'])]
#[UsePolicy(SocialAccountPolicy::class)]
#[UseFactory(SocialAccountFactory::class)]
class SocialAccount extends Model
{
    use CentralConnection;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class));
    }
}
