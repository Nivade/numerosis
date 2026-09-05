<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The private channel an owner listens on, matching the `user.{userId}`
 * binding in `routes/channels.php`.
 */
final class OwnerChannel
{
    /**
     * @return list<PrivateChannel>
     */
    public static function forId(int|string $ownerId): array
    {
        return [new PrivateChannel("user.{$ownerId}")];
    }

    /**
     * The channel is keyed on the `CentralUser` primary key, but provisioning
     * events are raised from contexts carrying only the `global_id`. An owner
     * that no longer resolves broadcasts nowhere.
     *
     * @return list<PrivateChannel>
     */
    public static function forGlobalId(?string $globalId): array
    {
        if ($globalId === null) {
            return [];
        }

        /** @var int|null $ownerId */
        $ownerId = Numerosis::model(CentralUser::class)::where('global_id', $globalId)->value('id');

        return $ownerId === null ? [] : self::forId($ownerId);
    }
}
