<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Contracts\Tenancy\ReadsContributions;
use Nvade\Numerosis\Contracts\Tenancy\RequiresContributions;
use Nvade\Numerosis\Database\Factories\Central\TenantProvisionFactory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The record of one tenant being provisioned, from the moment its slug is
 * claimed at checkout (`reserved`) through to `completed`.
 *
 * It outlives the provision: `completed_at` is stamped rather than the row
 * deleted, and tenant readiness stays `tenants.provisioned_at`.
 * `step_records` is written by `RunProvisioningStep`, one entry per step.
 *
 * @property string $slug
 * @property string|null $custom_domain
 * @property string $name
 * @property string|null $global_id
 * @property string|null $payment_plan
 * @property BillingCycle|null $billing_cycle
 * @property string|null $promotion_code
 * @property string|null $stripe_setup_intent_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property string|null $central_user_id
 * @property TenantProvisionStatus $status
 * @property Carbon|null $settled_at
 * @property Carbon|null $provisioning_started_at
 * @property Carbon|null $completed_at
 * @property array<string, array{outcome: string, reason?: string, attempts?: int, at: string}> $step_records
 * @property array<string, array<string, mixed>> $contributions
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 *
 * @mixin Model
 */
#[WithoutIncrementing]
#[UseFactory(TenantProvisionFactory::class)]
#[Unguarded]
class TenantProvision extends Model
{
    use CentralConnection;

    /** @use HasFactory<TenantProvisionFactory> */
    use HasFactory;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    public function hasFailed(): bool
    {
        return $this->status === TenantProvisionStatus::Failed;
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * A tenant's primary key is its slug, so this is null for any step that
     * runs before `CreateTenant` in `numerosis.tenancy.provisioning.steps`.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(Tenant::class), 'slug', 'id');
    }

    /**
     * How long a provisioning chain may hold a slug before another dispatch
     * is allowed to take it over. The backstop for a worker killed mid-chain,
     * which would otherwise hold the slug forever.
     */
    public const STALE_AFTER_MINUTES = 15;

    /**
     * Claims the slug for one provisioning chain, as a single conditional
     * update whose affected-row count is the answer. No cache driver is
     * involved, so a stuck provision stays queryable, which is the state
     * `tenancy:prune-stalled-provisions` reads.
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
     * Records the plan and SetupIntent chosen for an inline checkout. Scoped
     * to the owner as well as the slug — an unscoped write would overwrite a
     * stranger's SetupIntent and lock them out of their reservation.
     */
    public static function claimSetupIntent(
        string $slug,
        ?string $globalId,
        string $paymentPlan,
        BillingCycle $billingCycle,
        string $setupIntentId,
    ): void {
        static::query()
            ->where('slug', $slug)
            ->where('global_id', $globalId)
            ->update([
                'payment_plan' => $paymentPlan,
                'billing_cycle' => $billingCycle->value,
                'stripe_setup_intent_id' => $setupIntentId,
            ]);
    }

    /**
     * Whether the slug's reservation belongs to this global id. The slug
     * arrives as a client-controlled argument, the same shape as a route
     * parameter, so a caller re-checking ownership before acting on it needs
     * this rather than trusting whatever it already rendered.
     */
    public static function ownedBy(string $slug, ?string $globalId): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->where('global_id', $globalId)
            ->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function completedBefore(Builder $query, Carbon $cutoff): Builder
    {
        return $query
            ->where('status', TenantProvisionStatus::Completed)
            ->where('completed_at', '<', $cutoff);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function reservedBefore(Builder $query, Carbon $cutoff): Builder
    {
        return $query
            ->where('status', TenantProvisionStatus::Reserved)
            ->where('created_at', '<', $cutoff);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function provisioningBefore(Builder $query, Carbon $cutoff): Builder
    {
        return $query
            ->where('status', TenantProvisionStatus::Provisioning)
            ->where('created_at', '<', $cutoff);
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
        return $this->outcomeOf($step)?->finishesStep() === true;
    }

    public function outcomeOf(string $step): ?StepOutcome
    {
        $outcome = ($this->step_records ?? [])[$step]['outcome'] ?? null;

        return is_string($outcome) ? StepOutcome::tryFrom($outcome) : null;
    }

    /** The step whose last attempt threw, if the row carries one. */
    public function failedStep(): ?string
    {
        foreach (array_keys($this->step_records ?? []) as $step) {
            if ($this->outcomeOf($step) === StepOutcome::Failed) {
                return $step;
            }
        }

        return null;
    }

    /** How many times the failing step was tried before its retries ran out. */
    public function failedAttempts(): ?int
    {
        $step = $this->failedStep();
        $attempts = $step === null ? null : (($this->step_records ?? [])[$step]['attempts'] ?? null);

        return is_int($attempts) ? $attempts : null;
    }

    /**
     * @param  class-string  $step
     */
    public function recordStep(string $step, StepOutcome $outcome, ?string $reason = null, ?int $attempts = null): void
    {
        $this->forceFill([
            'step_records' => [
                ...($this->step_records ?? []),
                $step => array_filter([
                    'outcome' => $outcome->value,
                    'reason' => $reason,
                    'attempts' => $attempts,
                    'at' => now()->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null),
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
        foreach (ConfiguredSteps::provisioningSteps() as $step) {
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

        return $step === null
            ? __('numerosis::tenancy.provisioning.fallback')
            : self::labelFor($step);
    }

    /**
     * @param  class-string  $step
     */
    public static function labelFor(string $step): string
    {
        $key = 'numerosis::tenancy.provisioning.steps.'.class_basename($step);

        return Lang::has($key)
            ? __($key)
            : Str::headline(class_basename($step));
    }

    /**
     * The column-backed contributions a configured step declared, through
     * `RequiresContributions::requires()` or `ReadsContributions::reads()`.
     * A column-backed contribution no step declares is invisible here.
     *
     * @return list<class-string<ProvisionContribution>>
     */
    private static function columnBackedContributions(): array
    {
        $declared = [];

        foreach (ConfiguredSteps::provisioningSteps() as $step) {
            $declared = [
                ...$declared,
                ...(is_a($step, RequiresContributions::class, true) ? $step::requires() : []),
                ...(is_a($step, ReadsContributions::class, true) ? $step::reads() : []),
            ];
        }

        return array_values(array_unique(array_filter(
            $declared,
            static fn (string $c): bool => is_a($c, PersistsToProvisionColumns::class, true),
        )));
    }

    /**
     * Every contribution the row carries: the column-backed ones declared by
     * the configured steps, plus every entry in the JSON blob, which is
     * self-describing because it is keyed by class.
     *
     * A registry is needed only for the column-backed half — nothing about a
     * set of columns says which contribution owns them.
     *
     * @return list<ProvisionContribution>
     */
    public function allContributions(): array
    {
        $contributions = [];

        foreach (self::columnBackedContributions() as $contribution) {
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
