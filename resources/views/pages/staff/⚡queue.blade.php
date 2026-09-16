<?php

use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetQueueHealth;
use Nvade\Numerosis\Actions\Queries\GetSystemHealth;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Observability\HealthReport;
use Nvade\Numerosis\Data\Observability\QueueHealth;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    #[Computed]
    public function health(): HealthReport
    {
        return GetSystemHealth::run();
    }

    /**
     * @return array<string, QueueHealth>
     */
    #[Computed]
    public function queues(): array
    {
        $health = [];

        foreach ($this->queueNames() as $queue) {
            $health[$queue] = GetQueueHealth::run($queue);
        }

        return $health;
    }

    /**
     * Provisioning first: it is the queue whose depth means a customer is
     * still waiting on a signup.
     *
     * @return list<string>
     */
    private function queueNames(): array
    {
        $connection = Config::string('queue.default', 'sync');
        $configured = Config::get("queue.connections.{$connection}.queue", 'default');

        return array_values(array_unique([
            ProvisionTenant::QUEUE,
            is_string($configured) ? $configured : 'default',
        ]));
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.queue.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.queue.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.queue.subheading') }}</x-numerosis::ui.subheading>
        </div>

        <x-numerosis::ui.card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('numerosis::staff.queue.columns.queue') }}</flux:table.column>
                    <flux:table.column>{{ __('numerosis::staff.queue.columns.depth') }}</flux:table.column>
                    <flux:table.column>{{ __('numerosis::staff.queue.columns.oldest') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->queues as $name => $queue)
                        <flux:table.row :key="$name">
                            <flux:table.cell>{{ $name }}</flux:table.cell>
                            <flux:table.cell>{{ $queue->depth ?? __('numerosis::staff.queue.unknown') }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $queue->oldestJobSeconds === null
                                    ? __('numerosis::staff.queue.unknown')
                                    : $queue->oldestJobSeconds.'s' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-numerosis::ui.card>

        <x-numerosis::ui.card>
            <x-numerosis::ui.heading :level="2">{{ __('numerosis::staff.queue.health') }}</x-numerosis::ui.heading>

            <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.queue.failed_jobs') }}</dt>
                    <dd>{{ $this->health->queue->failedJobs ?? __('numerosis::staff.queue.unknown') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.queue.scheduler') }}</dt>
                    <dd>
                        {{ $this->health->schedulerLastRunSeconds === null
                            ? __('numerosis::staff.queue.never')
                            : $this->health->schedulerLastRunSeconds.'s' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.queue.failed_provisions') }}</dt>
                    <dd>{{ $this->health->provisioning->failedLastHour }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.queue.stalled_provisions') }}</dt>
                    <dd>{{ $this->health->provisioning->stalled }}</dd>
                </div>
            </dl>
        </x-numerosis::ui.card>
    </div>
</section>
