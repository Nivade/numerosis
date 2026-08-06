<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Nvade\Numerosis\Models\Central\Feature;
use Nvade\Numerosis\Models\Central\PaymentPlan;

class PaymentPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $featuresList = [
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

        $features = collect($featuresList)->map(fn ($feature) => Feature::create($feature));

        $plans = collect(config('numerosis.billing.plans', []))->keyBy('slug');

        // Starter: First 3 features available
        PaymentPlan::factory()->create([
            'name' => 'Starter',
            'monthly_price' => 10 * 100,
            'yearly_price' => 8 * 12 * 100,
            'trial_days' => 14,
            'available' => true,
            'monthly_id' => $plans->get('starter')['monthly_id'] ?? null,
            'yearly_id' => $plans->get('starter')['yearly_id'] ?? null,
        ])->features()->attach(
            $features->mapWithKeys(fn ($f, $i) => [$f->id => ['available' => $i < 3]])->toArray()
        );

        // Professional: First 7 features available
        PaymentPlan::factory()->create([
            'name' => 'Professional',
            'monthly_price' => 30 * 100,
            'yearly_price' => 20 * 12 * 100,
            'available' => true,
            'trial_days' => 0,
            'monthly_id' => $plans->get('professional')['monthly_id'] ?? null,
            'yearly_id' => $plans->get('professional')['yearly_id'] ?? null,
        ])->features()->attach(
            $features->mapWithKeys(fn ($f, $i) => [$f->id => ['available' => $i < 7]])->toArray()
        );

        // Enterprise: All 10 features available
        PaymentPlan::factory()->create([
            'name' => 'Enterprise',
            'monthly_price' => 83 * 100,
            'yearly_price' => 65 * 12 * 100,
            'available' => true,
            'trial_days' => 0,
            'monthly_id' => $plans->get('enterprise')['monthly_id'] ?? null,
            'yearly_id' => $plans->get('enterprise')['yearly_id'] ?? null,
        ])->features()->attach(
            $features->mapWithKeys(fn ($f) => [$f->id => ['available' => true]])->toArray()
        );
    }

    protected function runDefaultSeeder(): void
    {
        // ... (existing code for default features and plans can be moved here if needed)
    }
}
