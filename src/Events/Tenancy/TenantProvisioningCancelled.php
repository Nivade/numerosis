<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantProvisioningCancelled implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly ?string $globalId,
    ) {}

    public function broadcastOn(): array
    {
        // The channel is keyed on the CentralUser id, but failures are raised
        // from contexts that only carry the global_id, so resolve it here.
        /** @var int|null $ownerId */
        $ownerId = $this->globalId
            ? CentralUser::where('global_id', $this->globalId)->value('id')
            : null;

        return $ownerId !== null ? [new PrivateChannel("user.{$ownerId}")] : [];
    }

    public function broadcastAs(): string
    {
        return 'tenant.provisioning-cancelled';
    }
}
