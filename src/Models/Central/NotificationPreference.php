<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One person's override for one notification type. A missing row means "the
 * default", never "off".
 *
 * @property int $id
 * @property string $global_id
 * @property NotificationType $type
 * @property bool|null $mail
 * @property bool|null $database
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Model
 */
#[Fillable(['global_id', 'type', 'mail', 'database'])]
class NotificationPreference extends Model
{
    use CentralConnection;

    #[Override]
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'mail' => 'boolean',
            'database' => 'boolean',
        ];
    }
}
