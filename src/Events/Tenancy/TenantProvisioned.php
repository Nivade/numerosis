<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Tenant;

class TenantProvisioned implements ShouldBroadcast
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
        return 'tenant.provisioned';
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
