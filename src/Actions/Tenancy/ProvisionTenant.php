<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Bus;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Exceptions\Tenancy\ProvisioningAlreadyClaimed;
use Nvade\Numerosis\Jobs\RunProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Throwable;

/**
 * Turns a provisioning request into a queued chain, one link per configured
 * step. Reach it through {@see ProvisionsTenant::queue()}.
 *
 * Nothing runs synchronously beyond writing the provision row and claiming the
 * slug.
 */
class ProvisionTenant implements ProvisionsTenant
{
    use AsAction;

    public function queue(TenantProvisionData $data): void
    {
        $slug = $this->claimFor($data);

        if ($slug === null) {
            return;
        }

        Bus::chain($this->links($slug))
            ->onQueue('provisioning')
            // Scalars only: `->catch()` callbacks are wrapped in a
            // SerializableClosure, which serializes the whole `use` scope, so
            // capturing the model would drag it into the payload.
            ->catch(static fn (Throwable $e) => self::recordFailure($slug, $e))
            ->dispatch();
    }

    /**
     * @throws ProvisioningAlreadyClaimed
     */
    public function now(TenantProvisionData $data): void
    {
        $slug = $this->claimFor($data);

        if ($slug === null) {
            throw new ProvisioningAlreadyClaimed("Provisioning for [{$data->slug}] is already running.");
        }

        // Not `Bus::chain(...)->onConnection('sync')`: a chain defers the
        // links after the first, so the failure of a later one surfaces
        // through the queue rather than out of this call.
        foreach ($this->links($slug) as $link) {
            try {
                dispatch_sync($link);
            } catch (Throwable $e) {
                self::recordFailure($slug, $e);

                throw $e;
            }
        }
    }

    /**
     * @return string|null The claimed slug, or null when a chain already holds
     *                     it — the checkout redirect and the Stripe webhook
     *                     both arrive for the same tenant.
     */
    private function claimFor(TenantProvisionData $data): ?string
    {
        $provision = $this->recordRequest($data);

        return Numerosis::model(TenantProvision::class)::claim($provision->slug)
            ? $provision->slug
            : null;
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

        $provision->fill(['name' => $data->name]);

        $provision->applyContributions($data->contributions);

        $provision->save();

        return $provision;
    }

    /**
     * @return list<RunProvisioningStep>
     */
    private function links(string $slug): array
    {
        return array_map(
            static fn (string $step): RunProvisioningStep => new RunProvisioningStep($slug, $step),
            ConfiguredSteps::provisioningSteps(),
        );
    }

    private static function recordFailure(string $slug, Throwable $e): void
    {
        MarkProvisionFailed::run($slug, $e->getMessage());

        $globalId = Numerosis::model(TenantProvision::class)::where('slug', $slug)->value('global_id');

        event(new TenantProvisioningFailed($slug, is_string($globalId) ? $globalId : null));
    }
}
