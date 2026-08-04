<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->ownerId}")];
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
