<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Jobs\RunProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Throwable;

/**
 * Turns a provisioning request into a queued chain, one link per configured
 * step. Reach it through {@see ProvisionsTenant::queue()}.
 *
 * Nothing runs synchronously here beyond writing the provision row and
 * claiming the slug. The first step used to run inline because `Bus::chain()`
 * serializes every link up front and so could not hand a later step a `Tenant`
 * an earlier one produced; steps take the provision row and resolve what they
 * need from it, which removes the reason.
 */
class ProvisionTenant implements ProvisionsTenant
{
    use AsAction;

    public function queue(TenantProvisionData $data): void
    {
        $provision = $this->recordRequest($data);

        // Not claimed means a chain already holds this slug — the checkout
        // redirect and the Stripe webhook both arrive for the same tenant.
        if (! Numerosis::model(TenantProvision::class)::claim($provision->slug)) {
            return;
        }

        $this->dispatchChain($provision->slug);
    }

    /**
     * The row is the pipeline's only input, so the request is written to it
     * before the chain is dispatched rather than carried in every job payload.
     */
    private function recordRequest(TenantProvisionData $data): TenantProvision
    {
        $provisionClass = Numerosis::model(TenantProvision::class);

        /** @var TenantProvision $provision */
        $provision = $provisionClass::firstOrNew(['slug' => $data->slug]);

        $provision->fill([
            'name' => $data->name,
            'global_id' => $data->global_id,
        ]);

        $provision->applyContributions($data->contributions);

        $provision->save();

        return $provision;
    }

    private function dispatchChain(string $slug): void
    {
        /** @var list<class-string<ProvisioningStep>> $steps */
        $steps = Config::array('numerosis.tenancy.provisioning.steps', []);

        $links = array_map(
            static fn (string $step): RunProvisioningStep => new RunProvisioningStep($slug, $step),
            $steps,
        );

        Bus::chain($links)
            ->onQueue('provisioning')
            // Scalars only: `->catch()` callbacks are wrapped in a
            // SerializableClosure, which serializes the whole `use` scope, so
            // capturing the model would drag it into the payload.
            ->catch(function (Throwable $e) use ($slug): void {
                MarkProvisionFailed::run($slug, $e->getMessage());

                $globalId = Numerosis::model(TenantProvision::class)::where('slug', $slug)->value('global_id');

                event(new TenantProvisioningFailed($slug, is_string($globalId) ? $globalId : null));
            })
            ->dispatch();
    }

    /**
     * Hands the slug back. The chain is no longer in flight, whatever the
     * outcome, so holding the claim would only block a retry.
     */
    public static function release(string $slug, TenantProvisionStatus $status): void
    {
        Numerosis::model(TenantProvision::class)::where('slug', $slug)->update([
            'status' => $status,
            'provisioning_started_at' => null,
        ]);
    }
}
