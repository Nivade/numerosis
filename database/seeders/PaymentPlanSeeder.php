<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Example plans, shipped so a fresh install has something purchasable. A
 * consumer is expected to replace the prices and Stripe ids; the shape is the
 * point, not the numbers.
 *
 * Re-runnable. `numerosis:install --seed` calls this, and an install command
 * that cannot be run twice is not one anybody will run at all — so every write
 * below is keyed on a natural key (`features.slug`, `payment_plans.slug`)
 * rather than created unconditionally.
 *
 * It also no longer builds plans through `PaymentPlan::factory()`. That gave
 * every seeded plan a **faker** slug and a lorem-ipsum description, which is
 * worse than untidy: `.claude/rules/billing-checkout.md` records that
 * `PaymentPlanRepository::findBySlug()` is the single choke point every
 * checkout path shares, and `StartCheckoutRequest` validates the submitted
 * plan with `exists:central.payment_plans,slug`. A random slug means no
 * checkout URL for the plan can be written down, and the config keys below
 * (`numerosis.billing.plans.starter`, …) had no row they could ever line up
 * with. Factories belong in tests, where a random slug is a feature.
 */
class PaymentPlanSeeder extends Seeder
{
    /**
     * Ordered: each plan below grants the first N of these.
     *
     * @var list<array{slug: string, description: string}>
     */
    private const FEATURES = [
        ['slug' => 'basic-analytics', 'description' => 'Access to basic usage reports and analytics dashboard.'],
        ['slug' => 'user-management', 'description' => 'Manage multiple user accounts and permissions.'],
        ['slug' => 'api-access', 'description' => 'Integration capabilities via our RESTful API.'],
        ['slug' => 'custom-branding', 'description' => 'Remove our logo and add your own branding to the interface.'],
        ['slug' => 'priority-support', 'description' => 'Get faster response times from our support team.'],
        ['slug' => 'advanced-security', 'description' => 'Enable SSO, 2FA, and advanced audit logs.'],
        ['slug' => 'automated-backups', 'description' => 'Daily automated backups of all your tenant data.'],
        ['slug' => 'unlimited-storage', 'description' => 'No limits on the amount of data you can store.'],
        ['slug' => 'dedicated-account-manager', 'description' => 'A dedicated contact person for all your needs.'],
        ['slug' => 'custom-integrations', 'description' => 'Bespoke integration solutions tailored for your business.'],
    ];

    /**
     * Prices are minor currency units (cents), matching
     * `config('numerosis.modules.catalogue')` and Stripe itself.
     *
     * @var list<array{name: string, slug: string, description: string, monthly_price: int, yearly_price: int, trial_days: int, features: int}>
     */
    private const PLANS = [
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'Everything a small team needs to get going.',
            'monthly_price' => 10 * 100,
            'yearly_price' => 8 * 12 * 100,
            'trial_days' => 14,
            'features' => 3,
        ],
        [
            'name' => 'Professional',
            'slug' => 'professional',
            'description' => 'For growing teams that need integrations and support.',
            'monthly_price' => 30 * 100,
            'yearly_price' => 20 * 12 * 100,
            'trial_days' => 0,
            'features' => 7,
        ],
        [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'description' => 'Every feature, with a dedicated account manager.',
            'monthly_price' => 83 * 100,
            'yearly_price' => 65 * 12 * 100,
            'trial_days' => 0,
            'features' => 10,
        ],
    ];

    public function run(): void
    {
        $features = $this->seedFeatures();

        /** @var array<string, array{monthly_id?: string|null, yearly_id?: string|null}> $configured */
        $configured = collect(config('numerosis.billing.plans', []))->keyBy('slug')->all();

        foreach (self::PLANS as $plan) {
            $this->seedPlan($plan, $features, $configured[$plan['slug']] ?? []);
        }
    }

    /**
     * @return Collection<int, PlanFeature> in the declared order, which is what
     *                                      makes "the first N features" meaningful
     */
    private function seedFeatures(): Collection
    {
        /** @var class-string<PlanFeature> $featureClass */
        $featureClass = Numerosis::model(PlanFeature::class);

        return collect(self::FEATURES)->map(
            fn (array $feature): PlanFeature => $featureClass::firstOrCreate(
                ['slug' => $feature['slug']],
                ['description' => $feature['description']],
            ),
        );
    }

    /**
     * @param  array{name: string, slug: string, description: string, monthly_price: int, yearly_price: int, trial_days: int, features: int}  $attributes
     * @param  Collection<int, PlanFeature>  $features
     * @param  array{monthly_id?: string|null, yearly_id?: string|null}  $configured
     */
    private function seedPlan(array $attributes, Collection $features, array $configured): void
    {
        /** @var class-string<PaymentPlan> $planClass */
        $planClass = Numerosis::model(PaymentPlan::class);

        $plan = $planClass::updateOrCreate(
            ['slug' => $attributes['slug']],
            [
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'monthly_price' => $attributes['monthly_price'],
                'yearly_price' => $attributes['yearly_price'],
                'trial_days' => $attributes['trial_days'],
                'available' => true,
                'monthly_id' => $configured['monthly_id'] ?? null,
                'yearly_id' => $configured['yearly_id'] ?? null,
            ],
        );

        // sync(), not attach(): attach() on a re-run duplicates every pivot
        // row, and the pivot carries the `available` flag the plan comparison
        // table renders from.
        $plan->features()->sync(
            $features
                ->mapWithKeys(fn (PlanFeature $feature, int $index): array => [
                    $feature->id => ['available' => $index < $attributes['features']],
                ])
                ->all()
        );
    }
}
