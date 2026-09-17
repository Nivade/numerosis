<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Append-only: a consent record is evidence of what was agreed and when, so
 * withdrawing consent writes a new row rather than editing this one. Survives
 * anonymization, which is why it names a global id and holds no name or email.
 *
 * @property int $id
 * @property string $global_user_id
 * @property string $purpose
 * @property string|null $terms_version
 * @property string|null $ip_address
 * @property Carbon $granted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Model
 */
#[Fillable([
    'global_user_id',
    'purpose',
    'terms_version',
    'ip_address',
    'granted_at',
])]
class Consent extends Model
{
    use CentralConnection;

    #[Override]
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }
}
