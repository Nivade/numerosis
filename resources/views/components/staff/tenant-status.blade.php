@props(['tenant'])

@if ($tenant->isClosed())
    <flux:badge color="red" size="sm">{{ __('numerosis::staff.status.closed') }}</flux:badge>
@elseif ($tenant->isSuspended())
    <flux:badge color="amber" size="sm">{{ __('numerosis::staff.status.suspended') }}</flux:badge>
@elseif (! $tenant->isProvisioned())
    <flux:badge color="zinc" size="sm">{{ __('numerosis::staff.status.provisioning') }}</flux:badge>
@else
    <flux:badge color="green" size="sm">{{ __('numerosis::staff.status.active') }}</flux:badge>
@endif
