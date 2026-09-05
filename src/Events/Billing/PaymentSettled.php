<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Broadcasting\OwnerChannel;

/**
 * Broadcast so ⚡mine updates live, matching TenantProvisioned. Fired on
 * recovery (RestoreTenant) so a payment-status banner clears without a
 * manual refresh.
 */
class PaymentSettled implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string|int $ownerId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return OwnerChannel::forId($this->ownerId);
    }

    public function broadcastAs(): string
    {
        return 'payment.settled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'domain' => $this->tenant->id,
            'name' => $this->tenant->name,
        ];
    }
}
