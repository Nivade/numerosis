<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Database\Factories\Central\TenantProvisionFactory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The record of one tenant being provisioned, from the moment its slug is
 * claimed at checkout (`reserved`) through to `completed`.
 *
 * It outlives the provision: `completed_at` is stamped rather than the row
 * deleted. Tenant readiness is still `tenants.provisioned_at`, never a row
 * here.
 *
 * `step_records` is written by `RunProvisioningStep`, one entry per step.
 *
 * @property string $slug
 * @property string|null $custom_domain
 * @property string $name
 * @property string|null $global_id
 * @property string|null $payment_plan
 * @property BillingCycle|null $billing_cycle
 * @property string|null $stripe_setup_intent_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property string|null $central_user_id
 * @property TenantProvisionStatus $status
 * @property Carbon|null $settled_at
 * @property Carbon|null $provisioning_started_at
 * @property Carbon|null $completed_at
 * @property array<string, array{outcome: string, at: string}> $step_records
 * @property array<string, array<string, mixed>> $contributions
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Model
 */
#[WithoutIncrementing]
#[UseFactory(TenantProvisionFactory::class)]
class TenantProvision extends Model
{
    use CentralConnection;

    /** @use HasFactory<TenantProvisionFactory> */
    use HasFactory;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $guarded = [];

    public function hasFailed(): bool
    {
        return $this->status === TenantProvisionStatus::Failed;
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * How long a provisioning chain may hold a slug before another dispatch
     * is allowed to take it over. The backstop for a worker killed mid-chain,
     * which would otherwise hold the slug forever.
     */
    public const STALE_AFTER_MINUTES = 15;

    /**
     * Claims the slug for one provisioning chain, as a single conditional
     * update whose affected-row count is the answer.
     *
     * This replaced three overlapping cache mechanisms — `ShouldBeUnique`, a
     * `tenant-chain:` lock released in two unrelated files, and `CreateTenant`'s
     * own inner lock. One row, no cache driver requirement, and a stuck
     * provision is queryable rather than invisible until a TTL lapses;
     * `tenancy:prune-stalled-provisions` already reads exactly this state.
     */
    public static function claim(string $slug): bool
    {
        $claimed = static::query()
            ->where('slug', $slug)
            // Never a finished provision. Every step would be recorded done
            // and skip, including the one that reports the tenant ready, so
            // the row would sit on `provisioning` for good and read as stalled.
            ->where('status', '!=', TenantProvisionStatus::Completed)
            ->where(fn (Builder $query) => $query
                ->where('status', '!=', TenantProvisionStatus::Provisioning)
                ->orWhereNull('provisioning_started_at')
                ->orWhere('provisioning_started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES)))
            ->update([
                'status' => TenantProvisionStatus::Provisioning,
                'provisioning_started_at' => now(),
            ]);

        return $claimed === 1;
    }

    /**
     * @template TContribution of ProvisionContribution
     *
     * @param  class-string<TContribution>  $contribution
     * @return TContribution|null
     */
    public function contribution(string $contribution): ?ProvisionContribution
    {
        if (is_a($contribution, PersistsToProvisionColumns::class, true)) {
            /** @var TContribution|null */
            return $contribution::fromProvision($this);
        }

        $stored = $this->contributions[$contribution] ?? null;

        return is_array($stored) ? $contribution::from($stored) : null;
    }

    /**
     * Whether a step already ran to completion for this provision. A skipped
     * step counts: its contributions were absent, and a retry of the same
     * chain will find them absent again.
     *
     * @param  class-string  $step
     */
    public function hasRun(string $step): bool
    {
        return isset(($this->step_records ?? [])[$step]);
    }

    /**
     * @param  class-string  $step
     */
    public function recordStep(string $step, StepOutcome $outcome, ?string $reason = null): void
    {
        $this->forceFill([
            'step_records' => [
                ...($this->step_records ?? []),
                $step => array_filter([
                    'outcome' => $outcome->value,
                    'reason' => $reason,
                    'at' => now()->toIso8601String(),
                ]),
            ],
        ])->save();
    }

    /**
     * The step currently in flight: the one after the last recorded, in the
     * configured order. Null once every step has run.
     *
     * @return class-string|null
     */
    public function currentStep(): ?string
    {
        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.provisioning.steps', []);

        foreach ($steps as $step) {
            if (! $this->hasRun($step)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * What to show someone waiting on this provision.
     *
     * Translated by class basename so a host's own step needs no entry, and
     * falls back to the generic copy once every step has run, which is the
     * window between the last step and `tenants.provisioned_at` being read.
     */
    public function currentStepLabel(): string
    {
        $step = $this->currentStep();

        if ($step === null) {
            return __('numerosis::tenancy.provisioning.fallback');
        }

        $key = 'numerosis::tenancy.provisioning.steps.'.class_basename($step);

        return Lang::has($key)
            ? __($key)
            : Str::headline(class_basename($step));
    }

    /**
     * Every contribution the row carries: the column-backed ones named by
     * `numerosis.tenancy.provisioning.contributions`, plus every entry in the
     * JSON blob, which is self-describing because it is keyed by class.
     *
     * A registry is needed only for the column-backed half — nothing about a
     * set of columns says which contribution owns them.
     *
     * @return list<ProvisionContribution>
     */
    public function allContributions(): array
    {
        /** @var list<class-string<ProvisionContribution>> $registered */
        $registered = Config::array('numerosis.tenancy.provisioning.contributions', []);

        $contributions = [];

        foreach ($registered as $contribution) {
            $contributions[] = $this->contribution($contribution);
        }

        foreach (array_keys($this->contributions ?? []) as $stored) {
            if (! is_string($stored) || ! is_a($stored, ProvisionContribution::class, true)) {
                continue;
            }

            $contributions[] = $this->contribution($stored);
        }

        return array_values(array_filter($contributions));
    }

    /**
     * Writes contributions onto the row: column-backed ones into their own
     * columns, the rest into the JSON blob under their class name. Merged
     * rather than replaced, so a later stage contributing billing data does
     * not drop what registration contributed.
     *
     * @param  list<ProvisionContribution>  $contributions
     */
    public function applyContributions(array $contributions): void
    {
        $columns = [];
        $blob = $this->contributions ?? [];

        foreach ($contributions as $contribution) {
            if ($contribution instanceof PersistsToProvisionColumns) {
                $columns = [...$columns, ...array_filter(
                    $contribution->toProvisionColumns(),
                    static fn (mixed $value): bool => $value !== null,
                )];

                continue;
            }

            $blob[$contribution::class] = $contribution->toArray();
        }

        $this->fill([...$columns, 'contributions' => $blob]);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => TenantProvisionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'settled_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'step_records' => 'array',
            'contributions' => 'array',
        ];
    }
}
