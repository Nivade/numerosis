<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Numerosis;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider_id
 * @property string $provider
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CentralUser $user
 *
 * @mixin Model
 */
#[Fillable([
    'user_id',
    'provider_id',
    'provider',
])]
class SocialiteLogin extends Model
{
    use CentralConnection;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class));
    }
}
