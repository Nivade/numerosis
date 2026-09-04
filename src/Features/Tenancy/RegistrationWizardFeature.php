<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use LogicException;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Nvade\Numerosis\Livewire\Tenant\Registration as WizardRegistration;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps as Wizard;

/**
 * The self-serve tenant registration wizard and its route.
 *
 * Remove it from `numerosis.features` and there is no self-serve signup.
 * Provisioning itself is unaffected — the wizard is only one caller of it,
 * so creating tenants from an admin screen or a job keeps working.
 */
class RegistrationWizardFeature implements NamedFeature
{
    public const NAME = 'registration_wizard';

    /**
     * Written and read by the wizard, cleared by core's two checkout
     * completion paths (`CompleteRedirectCheckout`,
     * `Livewire\Billing\Checkout::settle()`).
     */
    public const SESSION_KEY = 'registration.wizard_state';

    /**
     * Alias each shipped step registers under, and the view file that alias
     * resolves to. `Steps\Payment` is deliberately absent: its natural alias
     * (`payment`) collides with Cashier's own published
     * `resources/views/vendor/cashier/payment.blade.php`
     * (`.ai/rules/billing-checkout.md`), so it keeps resolving by its
     * full FQCN instead — Livewire supports that with no `addComponent()`
     * call at all. Deriving every alias as `Str::kebab(class_basename($step))`
     * in a naive loop is exactly what would reintroduce that collision
     * (`.claude/plans/archive/memoized-tinkering-meadow.md`'s Phase 4.2) — this map
     * is the fix, not an oversight to "complete" later.
     *
     * A host-supplied step (added to `numerosis.tenancy.registration.steps`
     * but not in this map) is not auto-registered here — the package only
     * knows the view path for steps it ships. Register your own component
     * for it before adding it to that config key.
     *
     * @var array<class-string, string>
     */
    private const SHIPPED_STEP_ALIASES = [
        Wizard\CompanyInfo::class => 'company-info',
        Wizard\TechnicalSetup::class => 'technical-setup',
        Wizard\Plan::class => 'plan',
    ];

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Default step list. A host that already configured its own steps
        // wins: this only fills an unset key, and every feature's
        // bootstrap() runs well after config publishing and a host
        // provider's register() phase, so there is no register-order race
        // to protect against here.
        if (Config::get('numerosis.tenancy.registration.steps') === null) {
            Config::set('numerosis.tenancy.registration.steps', [
                Wizard\CompanyInfo::class,
                Wizard\TechnicalSetup::class,
                Wizard\Plan::class,
                Wizard\Payment::class,
            ]);
        }

        // Livewire::addComponent() takes an absolute path, so it is built
        // from `numerosis.views.path` rather than assumed as a relative
        // constant — see NumerosisServiceProvider::packageBooted().
        $viewsPath = Config::string('numerosis.views.path');

        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.registration.steps');

        $this->assertAStepProvidesTenantIdentity($steps);

        Livewire::addComponent(
            name: 'tenant-registration',
            viewPath: $viewsPath.'/livewire/tenant/registration/wizard/index.blade.php',
            class: WizardRegistration::class,
        );

        foreach ($steps as $step) {
            if (! isset(self::SHIPPED_STEP_ALIASES[$step])) {
                continue;
            }

            $alias = self::SHIPPED_STEP_ALIASES[$step];

            Livewire::addComponent(
                name: $alias,
                viewPath: $viewsPath.'/livewire/tenant/registration/wizard/steps/'.$alias.'.blade.php',
                class: $step,
            );
        }
    }

    /**
     * A step list with no identity source still renders a working wizard —
     * the missing identifier/display name only surfaces once
     * `ProvisionTenant`'s queued chain tries to build the tenant, far from
     * whoever misconfigured this key. Fail here instead.
     *
     * @param  list<class-string>  $steps
     */
    private function assertAStepProvidesTenantIdentity(array $steps): void
    {
        foreach ($steps as $step) {
            if (is_subclass_of($step, ProvidesTenantIdentity::class)) {
                return;
            }
        }

        throw new LogicException(
            'numerosis.tenancy.registration.steps must include at least one step implementing '
            .ProvidesTenantIdentity::class.', or the provisioning pipeline has no source for '
            .'the tenant\'s identifier or display name.'
        );
    }
}
